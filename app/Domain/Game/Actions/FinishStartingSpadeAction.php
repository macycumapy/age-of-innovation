<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TerrainType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinishStartingSpadeAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private OfferWorkshopAfterTerraformingAction $offerWorkshopAfterTerraforming,
        private ResolveCompletedStartingSetupAction $resolveCompletedStartingSetup,
        private ResolveScienceBonusPhaseAction $resolveScienceBonusPhase,
    ) {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interactionPhase = $lockedGame->phase;
            $interaction = $state->pendingInteraction;
            $hexId = $interaction?->context['selectedHexId'] ?? null;
            $stateVersionBefore = $lockedGame->version;
            $player = $lockedGame->players()
                ->whereKey($interaction?->playerId)
                ->whereBelongsTo($user)
                ->first();

            if (! in_array($lockedGame->phase, [GamePhase::Setup, GamePhase::Actions, GamePhase::ScienceBonus], true)
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::SpendSpades
                || ! is_string($hexId)
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сначала выберите клетку для преобразования.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            $spentSpades = max(1, (int) ($interaction->context['spentSpades'] ?? 1));

            if ($playerState === null || $playerState->unassignedSpades < $spentSpades) {
                throw ValidationException::withMessages(['game' => 'У игрока нет доступной лопаты.']);
            }

            $playerState->unassignedSpades -= $spentSpades;
            $terrainBefore = $interaction->context['terrainBefore'] ?? null;
            $terrainAfter = $interaction->context['terrainAfter'] ?? null;
            $remainingSpades = max(0, (int) ($interaction->context['remainingSpades'] ?? 1) - $spentSpades);
            $paidTools = (int) ($interaction->context['paidTools'] ?? 0);
            $paidSpadeCount = (int) ($interaction->context['paidSpadeCount'] ?? 0);
            $buildableHexIds = $interaction->context['buildableHexIds'] ?? [];

            if ($interactionPhase === GamePhase::Actions
                && $terrainAfter === $playerState->homeland->value) {
                $buildableHexIds[] = $hexId;
            }
            unset(
                $interaction->context['selectedHexId'],
                $interaction->context['terrainBefore'],
                $interaction->context['terrainAfter'],
                $interaction->context['paidTools'],
                $interaction->context['paidSpadeCount'],
                $interaction->context['spadesToSpend'],
                $interaction->context['spentSpades'],
            );
            $interaction->context['remainingSpades'] = $remainingSpades;
            $interaction->context['buildableHexIds'] = array_values(array_unique($buildableHexIds));
            $buildOffered = false;
            $incomeReceipts = [];

            if ($remainingSpades > 0) {
                $targetTerrain = TerrainType::from(
                    (string) $interaction->context['targetTerrain'],
                );
                $interaction->optionIds = $this->findEligibleTerraformHexes->execute(
                    $state,
                    $playerState,
                    $targetTerrain,
                );

                if ($interaction->optionIds !== []) {
                    $state->pendingInteraction = $interaction;
                    $nextPlayer = $player;
                    $nextPhase = $interactionPhase;
                } elseif ($interactionPhase === GamePhase::Actions) {
                    $buildOffered = $this->offerWorkshopAfterTerraforming->execute(
                        $state,
                        $playerState,
                        $buildableHexIds,
                    );
                    $nextPlayer = $player;
                    $nextPhase = GamePhase::Actions;
                } elseif ($interactionPhase === GamePhase::ScienceBonus) {
                    $state->pendingInteraction = null;
                    [$nextPlayer, $nextPhase, $incomeReceipts] = $this->resolveScienceBonusPhase->execute(
                        $state,
                        $lockedGame->players()->get(),
                    );
                } else {
                    $state->pendingInteraction = null;
                    [$nextPlayer, $nextPhase, $incomeReceipts] = $this->resolveCompletedStartingSetup->execute(
                        $state,
                        $lockedGame->players()->get(),
                    );
                }
            } elseif ($interactionPhase === GamePhase::Actions) {
                $buildOffered = $this->offerWorkshopAfterTerraforming->execute(
                    $state,
                    $playerState,
                    $buildableHexIds,
                );
                $nextPlayer = $player;
                $nextPhase = GamePhase::Actions;
            } elseif ($interactionPhase === GamePhase::ScienceBonus) {
                $state->pendingInteraction = null;
                [$nextPlayer, $nextPhase, $incomeReceipts] = $this->resolveScienceBonusPhase->execute(
                    $state,
                    $lockedGame->players()->get(),
                );
            } else {
                $state->pendingInteraction = null;
                [$nextPlayer, $nextPhase, $incomeReceipts] = $this->resolveCompletedStartingSetup->execute(
                    $state,
                    $lockedGame->players()->get(),
                );
            }

            $lockedGame->update([
                'status' => $nextPhase === GamePhase::Finished ? GameStatus::Finished : GameStatus::Active,
                'phase' => $nextPhase,
                'active_player_id' => $nextPlayer?->user_id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::SpendStartingSpade,
                [
                    'hex_id' => $hexId,
                    'terrain_before' => $terrainBefore,
                    'terrain_after' => $terrainAfter,
                    'remaining_spades' => $remainingSpades,
                    'target_terrain' => $interaction->context['targetTerrain'] ?? null,
                    'phase' => $interactionPhase->value,
                    'income_started' => $interactionPhase === GamePhase::Setup && $nextPhase !== GamePhase::Setup,
                    'round' => $state->round->number,
                    'buildable_hex_ids' => $buildableHexIds,
                    'build_offered' => $buildOffered,
                    'paid_tools' => $paidTools,
                    'paid_spade_count' => $paidSpadeCount,
                    'spades_spent' => $spentSpades,
                    'income_receipts' => $incomeReceipts,
                ],
                [
                    [
                        'type' => $interactionPhase === GamePhase::Setup
                            ? 'starting_spade_spent'
                            : 'spade_spent',
                        'player_id' => $player->id,
                        'hex_id' => $hexId,
                    ],
                    ...($interactionPhase === GamePhase::Setup && $nextPhase !== GamePhase::Setup ? [[
                        'type' => 'income_phase_started',
                        'round' => $state->round->number,
                    ]] : []),
                ],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
