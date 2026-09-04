<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseStartingCompetencyAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
        private DetermineStartingBuildingOrderAction $determineStartingBuildingOrder,
        private GrantCompetencyAction $grantCompetency,
        private ResolveCompletedStartingSetupAction $resolveCompletedStartingSetup,
    ) {
    }

    public function execute(Game $game, User $user, Competency $competency): Game
    {
        return DB::transaction(function () use ($game, $user, $competency): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $stateVersionBefore = $lockedGame->version;
            $player = $lockedGame->players()
                ->whereKey($interaction?->playerId)
                ->whereBelongsTo($user)
                ->first();
            $isBuildingChoice = $lockedGame->phase === GamePhase::Actions
                && ($interaction?->context['reason'] ?? null) === 'building';
            $isStartingChoice = $lockedGame->phase === GamePhase::Setup
                && $player?->faction === Faction::Monks;

            if ($lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::ChooseCompetency
                || ! $player instanceof GamePlayer
                || (! $isStartingChoice && ! $isBuildingChoice)
                || ! in_array($competency->value, $interaction->optionIds, true)) {
                throw ValidationException::withMessages([
                    'competency_id' => 'Эта компетенция недоступна.',
                ]);
            }

            $playerStateIndex = null;

            foreach ($state->players as $index => $playerState) {
                if ($playerState->playerId === $player->id) {
                    $playerStateIndex = $index;
                    break;
                }
            }

            if ($playerStateIndex === null) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $playerState = $state->players[$playerStateIndex];
            $this->grantCompetency->execute(
                $state,
                $playerState,
                $competency,
                $isBuildingChoice
                    ? $state->availableCompetencyIds
                    : ($state->setupPool?->competencies ?? []),
            );
            $state->players[$playerStateIndex] = $playerState;
            $state->pendingInteraction = null;

            if ($isBuildingChoice) {
                $builtHexId = (string) ($interaction->context['builtHexId'] ?? '');
                $nextActiveUserId = $this->createTownChoiceAfterBuilding->execute(
                    $state,
                    $playerState,
                    $builtHexId,
                );
                $lockedGame->update([
                    'active_player_id' => $nextActiveUserId,
                    'state' => $state,
                    'version' => $lockedGame->version + 1,
                ]);
                $this->appendGameHistory->execute(
                    $lockedGame,
                    $user,
                    GameActionType::ChooseCompetency,
                    [
                        'competency_id' => $competency->value,
                        'reason' => 'building',
                        'built_hex_id' => $builtHexId,
                    ],
                    [[
                        'type' => 'building_competency_chosen',
                        'player_id' => $player->id,
                        'competency_id' => $competency->value,
                        'built_hex_id' => $builtHexId,
                    ]],
                    $stateVersionBefore,
                    $lockedGame->version,
                );

                return $lockedGame->refresh();
            }

            $placementOrder = $this->determineStartingBuildingOrder->execute($lockedGame);
            $incomeReceipts = [];

            if ($state->startingBuildingTurnIndex >= count($placementOrder)) {
                [$nextPlayer, $nextPhase, $incomeReceipts] = $this->resolveCompletedStartingSetup->execute(
                    $state,
                    $lockedGame->players()->get(),
                );
            } else {
                $nextPlayer = $lockedGame->players()->whereKey($placementOrder[$state->startingBuildingTurnIndex])->firstOrFail();
                $nextPhase = GamePhase::Setup;
            }

            $lockedGame->update([
                'phase' => $nextPhase,
                'active_player_id' => $nextPlayer->user_id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::ChooseCompetency,
                [
                    'competency_id' => $competency->value,
                    'income_started' => $nextPhase !== GamePhase::Setup,
                    'round' => $state->round->number,
                    'income_receipts' => $incomeReceipts,
                ],
                [
                    [
                        'type' => 'starting_competency_chosen',
                        'player_id' => $player->id,
                        'competency_id' => $competency->value,
                        'next_player_id' => $nextPlayer->id,
                        'next_phase' => $nextPhase->value,
                    ],
                    ...($nextPhase !== GamePhase::Setup ? [[
                        'type' => 'income_phase_started',
                        'round' => $state->round->number,
                    ]] : []),
                ],
                $stateVersionBefore,
                $lockedGame->version,
                $nextPhase !== GamePhase::Setup,
            );

            return $lockedGame->refresh();
        });
    }
}
