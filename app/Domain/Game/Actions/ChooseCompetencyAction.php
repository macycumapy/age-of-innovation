<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class ChooseCompetencyAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyChooseCompetencyAction $applyChooseCompetency,
        private CreateNeutralBuildingInteractionAction $createNeutralBuildingInteraction,
        private DetermineStartingBuildingOrderAction $determineStartingBuildingOrder,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
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
            $isBuildingChoice = $lockedGame->phase->isActionPhase()
                && ($interaction?->context['reason'] ?? null) === 'building';
            $isInnovationChoice = $lockedGame->phase->isActionPhase()
                && ($interaction?->context['reason'] ?? null) === 'innovation';
            $isStartingChoice = $lockedGame->phase === GamePhase::Setup
                && in_array($player?->faction, [Faction::Monks, Faction::Inventors], true);

            if ($lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::ChooseCompetency
                || ! $player instanceof GamePlayer
                || (! $isStartingChoice && ! $isBuildingChoice && ! $isInnovationChoice)
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

            if ($isBuildingChoice || $isInnovationChoice) {
                $result = $this->applyChooseCompetency->execute($state, $playerState, $competency);
                $lockedGame->update([
                    'active_player_id' => $result->nextActiveUserId,
                    'state' => $state,
                    'version' => $lockedGame->version + 1,
                ]);
                $this->appendGameHistory->execute(
                    $lockedGame,
                    $user,
                    GameActionType::ChooseCompetency,
                    [
                        'competency_id' => $competency->value,
                        'reason' => $result->reason,
                        'built_hex_id' => $result->reason === 'building' ? $result->builtHexId : null,
                        'gained_power' => $result->gainedPower,
                        'victory_points' => $result->victoryPoints,
                    ],
                    [[
                        'type' => $result->reason === 'building' ? GameEventType::BuildingCompetencyChosen->value : GameEventType::InnovationCompetencyChosen->value,
                        'player_id' => $player->id,
                        'competency_id' => $competency->value,
                        'built_hex_id' => $result->reason === 'building' ? $result->builtHexId : null,
                    ]],
                    $stateVersionBefore,
                    $lockedGame->version,
                );

                return $lockedGame->refresh();
            }

            $knowledgeAdvance = $this->grantCompetency->execute(
                $state,
                $playerState,
                $competency,
                $state->setupPool === null ? $state->availableCompetencyIds : $state->setupPool->competencies,
            );
            $playerState->victoryPoints += $knowledgeAdvance->victoryPoints;
            $state->pendingInteraction = null;

            $placementOrder = $this->determineStartingBuildingOrder->execute($lockedGame);
            $incomeReceipts = [];
            $hasRemainingStartingBuildingPlacements = $state->startingBuildingTurnIndex < count($placementOrder);

            if ($competency === Competency::Competency05
                && $this->createTerraformingInteraction(
                    $state,
                    $playerState,
                    GamePhase::Setup,
                    $hasRemainingStartingBuildingPlacements,
                )) {
                $nextPlayer = $player;
                $nextPhase = GamePhase::Setup;
            } elseif ($competency === Competency::Competency10
                && $this->createNeutralBuildingInteraction->execute(
                    $state,
                    $playerState,
                    BuildingType::Tower,
                    [
                        'competency' => $competency->value,
                        'source' => 'competency',
                        'reason' => 'starting_competency',
                    ],
                )) {
                $nextPlayer = $player;
                $nextPhase = GamePhase::Setup;
            } elseif ($state->startingBuildingTurnIndex >= count($placementOrder)) {
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
                    'reason' => 'starting',
                    'income_started' => $nextPhase !== GamePhase::Setup,
                    'round' => $state->round->number,
                    'income_receipts' => $incomeReceipts,
                    'gained_power' => $knowledgeAdvance->gainedPower,
                ],
                [
                    [
                        'type' => GameEventType::StartingCompetencyChosen->value,
                        'player_id' => $player->id,
                        'competency_id' => $competency->value,
                        'next_player_id' => $nextPlayer->id,
                        'next_phase' => $nextPhase->value,
                    ],
                    ...($nextPhase !== GamePhase::Setup ? [[
                        'type' => GameEventType::IncomePhaseStarted->value,
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

    private function createTerraformingInteraction(
        GameStateData $state,
        GamePlayerStateData $playerState,
        GamePhase $phase = GamePhase::Actions,
        bool $resumeStartingBuildingPlacement = false,
    ): bool {
        $eligibleHexIds = $this->findEligibleTerraformHexes->execute($state, $playerState, $playerState->homeland);

        if ($eligibleHexIds === []) {
            return false;
        }

        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            $playerState->playerId,
            $eligibleHexIds,
            [
                'phase' => $phase->value,
                'spadeCount' => 2,
                'remainingSpades' => 2,
                'targetTerrain' => $playerState->homeland->value,
                ...($resumeStartingBuildingPlacement ? ['resumeStartingBuildingPlacement' => true] : []),
            ],
        );

        return true;
    }
}
