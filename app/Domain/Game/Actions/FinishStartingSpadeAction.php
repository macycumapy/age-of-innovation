<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;
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
        private DetermineStartingBuildingOrderAction $determineStartingBuildingOrder,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private FindEligibleMoleTunnelHexesAction $findEligibleMoleTunnelHexes,
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
            $goblinBonusCoins = $playerState->faction === Faction::Goblins ? $spentSpades * 2 : 0;
            $playerState->resources->coins += $goblinBonusCoins;
            $terrainBefore = $interaction->context['terrainBefore'] ?? null;
            $terrainAfter = $interaction->context['terrainAfter'] ?? null;
            $remainingSpades = max(0, (int) ($interaction->context['remainingSpades'] ?? 1) - $spentSpades);
            $paidTools = (int) ($interaction->context['paidTools'] ?? 0);
            $paidSpadeCount = (int) ($interaction->context['paidSpadeCount'] ?? 0);
            $tunnelTools = (int) ($interaction->context['tunnelTools'] ?? 0);
            $tunnelVictoryPoints = (int) ($interaction->context['tunnelVictoryPoints'] ?? 0);
            $roundScoringTile = RoundScoringTile::tryFrom((string) $state->round->scoringTileId);
            $roundScoringVictoryPoints = $interactionPhase === GamePhase::Actions
                && $roundScoringTile?->goal() === RoundScoringGoal::Spade
                    ? $spentSpades * 2
                    : 0;
            $playerState->victoryPoints += $roundScoringVictoryPoints;
            $buildableHexIds = $interaction->context['buildableHexIds'] ?? [];

            if ($tunnelTools > 0) {
                $interaction->context['tunnelUsed'] = true;
            }

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
                $interaction->context['tunnelTools'],
                $interaction->context['tunnelVictoryPoints'],
                $interaction->context['optionIdsBeforeSelection'],
            );
            $interaction->context['remainingSpades'] = $remainingSpades;
            $interaction->context['buildableHexIds'] = array_values(array_unique($buildableHexIds));
            $buildOffered = false;
            $incomeReceipts = [];
            $finalScoring = [];
            $scienceBonusReceipts = [];

            if ($remainingSpades > 0) {
                $targetTerrain = TerrainType::from(
                    (string) $interaction->context['targetTerrain'],
                );
                $interaction->optionIds = $this->findEligibleTerraformHexes->execute(
                    $state,
                    $playerState,
                    $targetTerrain,
                );
                if (! ($interaction->context['tunnelUsed'] ?? false)) {
                    $interaction->optionIds = array_values(array_unique([
                        ...$interaction->optionIds,
                        ...$this->findEligibleMoleTunnelHexes->execute($state, $playerState),
                    ]));
                }

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
                    [$nextPlayer, $nextPhase, $incomeReceipts, $finalScoring, $scienceBonusReceipts] = $this->resolveScienceBonusPhase->execute(
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
                [$nextPlayer, $nextPhase, $incomeReceipts, $finalScoring, $scienceBonusReceipts] = $this->resolveScienceBonusPhase->execute(
                    $state,
                    $lockedGame->players()->get(),
                );
            } else {
                $state->pendingInteraction = null;
                if (($interaction->context['chooseStartingCompetencyAfterSpade'] ?? false) === true) {
                    $state->pendingInteraction = new PendingInteractionData(
                        PendingInteractionType::ChooseCompetency,
                        $player->id,
                        array_values(array_unique(array_filter(
                            array_map(
                                static fn (Competency|string $competency): string => $competency instanceof Competency
                                    ? $competency->value
                                    : $competency,
                                $state->availableCompetencyIds,
                            ),
                            static fn (string $competencyId): bool => ! in_array(
                                $competencyId,
                                $playerState->competencyIds,
                                true,
                            ),
                        ))),
                    );
                    $nextPlayer = $player;
                    $nextPhase = GamePhase::Setup;
                } elseif (($interaction->context['resumeStartingBuildingPlacement'] ?? false) === true
                    && $state->startingBuildingTurnIndex < count($this->determineStartingBuildingOrder->execute($lockedGame))) {
                    $placementOrder = $this->determineStartingBuildingOrder->execute($lockedGame);
                    $nextPlayer = $lockedGame->players()
                        ->whereKey($placementOrder[$state->startingBuildingTurnIndex] ?? null)
                        ->first();

                    if (! $nextPlayer instanceof GamePlayer) {
                        throw ValidationException::withMessages(['game' => 'Нарушен порядок стартового выставления.']);
                    }

                    $nextPhase = GamePhase::Setup;
                } else {
                    [$nextPlayer, $nextPhase, $incomeReceipts] = $this->resolveCompletedStartingSetup->execute(
                        $state,
                        $lockedGame->players()->get(),
                    );
                }
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
                    'resume_starting_building_placement' => (bool) ($interaction->context['resumeStartingBuildingPlacement'] ?? false),
                    'choose_starting_competency_after_spade' => (bool) ($interaction->context['chooseStartingCompetencyAfterSpade'] ?? false),
                    'bonus_coins' => $goblinBonusCoins,
                    'tunnel_tools' => $tunnelTools,
                    'tunnel_victory_points' => $tunnelVictoryPoints,
                    'victory_points' => $roundScoringVictoryPoints,
                    'income_receipts' => $incomeReceipts,
                    'science_bonus_receipts' => $scienceBonusReceipts,
                    'final_scoring' => $finalScoring,
                ],
                [
                    [
                        'type' => $interactionPhase === GamePhase::Setup
                            ? 'starting_spade_spent'
                            : 'spade_spent',
                        'player_id' => $player->id,
                        'hex_id' => $hexId,
                    ],
                    ...($tunnelTools > 0 ? [[
                        'type' => 'mole_tunnel_used',
                        'player_id' => $player->id,
                        'hex_id' => $hexId,
                        'tools' => $tunnelTools,
                        'victory_points' => $tunnelVictoryPoints,
                    ]] : []),
                    ...($roundScoringVictoryPoints > 0 ? [[
                        'type' => 'round_spade_scored',
                        'player_id' => $player->id,
                        'spades' => $spentSpades,
                        'victory_points' => $roundScoringVictoryPoints,
                    ]] : []),
                    ...($goblinBonusCoins > 0 ? [[
                        'type' => 'goblin_spade_bonus_received',
                        'player_id' => $player->id,
                        'spades' => $spentSpades,
                        'coins' => $goblinBonusCoins,
                    ]] : []),
                    ...($interactionPhase === GamePhase::Setup && $nextPhase !== GamePhase::Setup ? [[
                        'type' => 'income_phase_started',
                        'round' => $state->round->number,
                    ]] : []),
                ],
                $stateVersionBefore,
                $lockedGame->version,
                $nextPhase !== $interactionPhase,
            );

            return $lockedGame->refresh();
        });
    }
}
