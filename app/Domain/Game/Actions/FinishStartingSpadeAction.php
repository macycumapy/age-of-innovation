<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\SpendSpadesHistoryData;
use App\Domain\Game\Data\SpendSpadesOptionData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TerrainType;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinishStartingSpadeAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplySpendSpadesAction $applySpendSpades,
        private DetermineStartingBuildingOrderAction $determineStartingBuildingOrder,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private FindEligibleMoleTunnelHexesAction $findEligibleMoleTunnelHexes,
        private FindEligiblePalaceFlightHexesAction $findEligiblePalaceFlightHexes,
        private ResolveCompletedStartingSetupAction $resolveCompletedStartingSetup,
        private ResolveScienceBonusPhaseAction $resolveScienceBonusPhase,
    ) {
    }

    public function execute(Game $game, GamePlayer $player): Game
    {
        return DB::transaction(function () use ($game, $player): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interactionPhase = $lockedGame->phase;
            $interaction = $state->pendingInteraction;
            $hexId = $interaction?->context['selectedHexId'] ?? null;
            $stateVersionBefore = $lockedGame->version;

            if (! in_array($lockedGame->phase, [GamePhase::Setup, GamePhase::Actions, GamePhase::ScienceBonus], true)
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== PendingInteractionType::SpendSpades
                || $interaction->playerId !== $player->id
                || ! is_string($hexId)
            ) {
                throw ValidationException::withMessages(['game' => 'Сначала выберите клетку для преобразования.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            $spentSpades = max(1, (int) ($interaction->context['spentSpades'] ?? 1));

            if ($playerState === null || $playerState->unassignedSpades < $spentSpades) {
                throw ValidationException::withMessages(['game' => 'У игрока нет доступной лопаты.']);
            }

            if ($interactionPhase->isActionPhase()) {
                return $this->finishActionPhaseSpades(
                    $lockedGame,
                    $player,
                    $interaction,
                    $playerState,
                    $hexId,
                    $spentSpades,
                    $stateVersionBefore,
                );
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
            $flightScholarCost = (int) ($interaction->context['flightScholarCost'] ?? 0);
            $flightVictoryPoints = (int) ($interaction->context['flightVictoryPoints'] ?? 0);
            $buildableHexIds = $interaction->context['buildableHexIds'] ?? [];

            if ($tunnelTools > 0) {
                $interaction->context['tunnelUsed'] = true;
            }
            if ($flightScholarCost > 0) {
                $interaction->context['flightUsed'] = true;
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
                $interaction->context['flightScholarCost'],
                $interaction->context['flightVictoryPoints'],
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
                if (! ($interaction->context['flightUsed'] ?? false)) {
                    $interaction->optionIds = array_values(array_unique([
                        ...$interaction->optionIds,
                        ...$this->findEligiblePalaceFlightHexes->execute($state, $playerState),
                    ]));
                }

                if ($interaction->optionIds !== []) {
                    $state->pendingInteraction = $interaction;
                    $nextPlayer = $player;
                    $nextPhase = $interactionPhase;
                } elseif ($interactionPhase === GamePhase::ScienceBonus) {
                    $state->pendingInteraction = null;
                    [$nextPlayerState, $nextPhase, $incomeReceipts, $finalScoring, $scienceBonusReceipts]
                        = $this->resolveScienceBonusPhase->execute($state);
                    $nextPlayer = $nextPlayerState === null
                        ? null
                        : $lockedGame->players()->findOrFail($nextPlayerState->playerId);
                } else {
                    $state->pendingInteraction = null;
                    $resolution = $this->resolveCompletedStartingSetup->execute($state);
                    $nextPlayer = $lockedGame->players()->findOrFail($resolution->nextActivePlayerId);
                    $nextPhase = $resolution->phase;
                    $incomeReceipts = $resolution->incomeReceipts;
                }
            } elseif ($interactionPhase === GamePhase::ScienceBonus) {
                $state->pendingInteraction = null;
                [$nextPlayerState, $nextPhase, $incomeReceipts, $finalScoring, $scienceBonusReceipts]
                    = $this->resolveScienceBonusPhase->execute($state);
                $nextPlayer = $nextPlayerState === null
                    ? null
                    : $lockedGame->players()->findOrFail($nextPlayerState->playerId);
            } else {
                $state->pendingInteraction = null;
                if (($interaction->context['chooseStartingCompetencyAfterSpade'] ?? false) === true) {
                    $state->pendingInteraction = new PendingInteractionData(
                        PendingInteractionType::ChooseCompetency,
                        $player->id,
                        array_values(array_unique(array_filter(
                            array_map(
                                static fn (string $competency): string => $competency,
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
                    $resolution = $this->resolveCompletedStartingSetup->execute($state);
                    $nextPlayer = $lockedGame->players()->findOrFail($resolution->nextActivePlayerId);
                    $nextPhase = $resolution->phase;
                    $incomeReceipts = $resolution->incomeReceipts;
                }
            }

            $lockedGame->update([
                'status' => $nextPhase === GamePhase::Finished ? GameStatus::Finished : GameStatus::Active,
                'phase' => $nextPhase,
                'active_game_player_id' => $nextPlayer?->id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendSpendSpadesHistory(
                $lockedGame,
                $player,
                new SpendSpadesHistoryData(
                    hexId: $hexId,
                    terrainBefore: is_string($terrainBefore) ? $terrainBefore : null,
                    terrainAfter: is_string($terrainAfter) ? $terrainAfter : null,
                    remainingSpades: $remainingSpades,
                    targetTerrain: is_string($interaction->context['targetTerrain'] ?? null)
                        ? $interaction->context['targetTerrain']
                        : null,
                    phase: $interactionPhase,
                    incomeStarted: $interactionPhase === GamePhase::Setup && $nextPhase !== GamePhase::Setup,
                    round: $state->round->number,
                    buildableHexIds: array_values(array_filter((array) $buildableHexIds, 'is_string')),
                    buildOffered: $buildOffered,
                    paidTools: $paidTools,
                    paidSpadeCount: $paidSpadeCount,
                    spentSpades: $spentSpades,
                    resumeStartingBuildingPlacement: (bool) ($interaction->context['resumeStartingBuildingPlacement'] ?? false),
                    chooseStartingCompetencyAfterSpade: (bool) ($interaction->context['chooseStartingCompetencyAfterSpade'] ?? false),
                    bonusCoins: $goblinBonusCoins,
                    tunnelTools: $tunnelTools,
                    tunnelVictoryPoints: $tunnelVictoryPoints,
                    flightScholarCost: $flightScholarCost,
                    flightVictoryPoints: $flightVictoryPoints,
                    victoryPoints: 0,
                    felineBonusPending: (bool) ($interaction->context['felineBonusPending'] ?? false),
                    lizardBonusPending: (bool) ($interaction->context['lizardBonusPending'] ?? false),
                    lizardFreeWorkshop: (bool) ($interaction->context['lizardFreeWorkshop'] ?? false),
                    incomeReceipts: $incomeReceipts,
                    scienceBonusReceipts: $scienceBonusReceipts,
                    finalScoring: $finalScoring,
                ),
                $stateVersionBefore,
                $lockedGame->version,
                $nextPhase !== $interactionPhase,
            );

            return $lockedGame->refresh();
        });
    }

    private function finishActionPhaseSpades(
        Game $game,
        GamePlayer $player,
        PendingInteractionData $interaction,
        GamePlayerStateData $playerState,
        string $hexId,
        int $spentSpades,
        int $stateVersionBefore,
    ): Game {
        $historyContext = $interaction->context;
        $result = $this->applySpendSpades->execute(
            $game->state,
            $playerState,
            new SpendSpadesOptionData($hexId, $spentSpades),
            requireActionPhase: false,
        );

        $game->update([
            'active_game_player_id' => $result->nextActivePlayerId,
            'state' => $game->state,
            'version' => $game->version + 1,
        ]);
        $this->appendSpendSpadesHistory(
            $game,
            $player,
            new SpendSpadesHistoryData(
                hexId: $result->hexId,
                terrainBefore: $result->terrainBefore,
                terrainAfter: $result->terrainAfter,
                remainingSpades: $result->remainingSpades,
                targetTerrain: $historyContext['targetTerrain'] ?? null,
                phase: GamePhase::Actions,
                incomeStarted: false,
                round: $game->state->round->number,
                buildableHexIds: $result->buildableHexIds,
                buildOffered: $result->buildOffered,
                paidTools: (int) ($historyContext['paidTools'] ?? 0),
                paidSpadeCount: (int) ($historyContext['paidSpadeCount'] ?? 0),
                spentSpades: $result->spentSpades,
                resumeStartingBuildingPlacement: false,
                chooseStartingCompetencyAfterSpade: false,
                bonusCoins: $result->bonusCoins,
                tunnelTools: (int) ($historyContext['tunnelTools'] ?? 0),
                tunnelVictoryPoints: (int) ($historyContext['tunnelVictoryPoints'] ?? 0),
                flightScholarCost: (int) ($historyContext['flightScholarCost'] ?? 0),
                flightVictoryPoints: (int) ($historyContext['flightVictoryPoints'] ?? 0),
                victoryPoints: $result->victoryPoints,
                felineBonusPending: (bool) ($historyContext['felineBonusPending'] ?? false),
                lizardBonusPending: (bool) ($historyContext['lizardBonusPending'] ?? false),
                lizardFreeWorkshop: (bool) ($historyContext['lizardFreeWorkshop'] ?? false),
            ),
            $stateVersionBefore,
            $game->version,
        );

        return $game->refresh();
    }

    private function appendSpendSpadesHistory(
        Game $game,
        GamePlayer $player,
        SpendSpadesHistoryData $history,
        int $stateVersionBefore,
        int $stateVersionAfter,
        bool $phaseChanged = false,
    ): void {
        $this->appendGameHistory->execute(
            $game,
            $player,
            GameActionType::SpendStartingSpade,
            [
                'hex_id' => $history->hexId,
                'terrain_before' => $history->terrainBefore,
                'terrain_after' => $history->terrainAfter,
                'remaining_spades' => $history->remainingSpades,
                'target_terrain' => $history->targetTerrain,
                'phase' => $history->phase->value,
                'income_started' => $history->incomeStarted,
                'round' => $history->round,
                'buildable_hex_ids' => $history->buildableHexIds,
                'build_offered' => $history->buildOffered,
                'paid_tools' => $history->paidTools,
                'paid_spade_count' => $history->paidSpadeCount,
                'spades_spent' => $history->spentSpades,
                'resume_starting_building_placement' => $history->resumeStartingBuildingPlacement,
                'choose_starting_competency_after_spade' => $history->chooseStartingCompetencyAfterSpade,
                'bonus_coins' => $history->bonusCoins,
                'tunnel_tools' => $history->tunnelTools,
                'tunnel_victory_points' => $history->tunnelVictoryPoints,
                'flight_scholar_cost' => $history->flightScholarCost,
                'flight_victory_points' => $history->flightVictoryPoints,
                'victory_points' => $history->victoryPoints,
                'feline_bonus_pending' => $history->felineBonusPending,
                'lizard_bonus_pending' => $history->lizardBonusPending,
                'lizard_free_workshop' => $history->lizardFreeWorkshop,
                'income_receipts' => $history->incomeReceipts,
                'science_bonus_receipts' => $history->scienceBonusReceipts,
                'final_scoring' => $history->finalScoring,
            ],
            [
                [
                    'type' => ($history->phase === GamePhase::Setup
                        ? GameEventType::StartingSpadeSpent
                        : GameEventType::SpadeSpent)->value,
                    'player_id' => $player->id,
                    'hex_id' => $history->hexId,
                ],
                ...($history->tunnelTools > 0 ? [[
                    'type' => GameEventType::MoleTunnelUsed->value,
                    'player_id' => $player->id,
                    'hex_id' => $history->hexId,
                    'tools' => $history->tunnelTools,
                    'victory_points' => $history->tunnelVictoryPoints,
                ]] : []),
                ...($history->flightScholarCost > 0 ? [[
                    'type' => GameEventType::PalaceFlightUsed->value,
                    'player_id' => $player->id,
                    'hex_id' => $history->hexId,
                    'scholars' => $history->flightScholarCost,
                    'victory_points' => $history->flightVictoryPoints,
                ]] : []),
                ...($history->victoryPoints > 0 ? [[
                    'type' => GameEventType::RoundSpadeScored->value,
                    'player_id' => $player->id,
                    'spades' => $history->spentSpades,
                    'victory_points' => $history->victoryPoints,
                ]] : []),
                ...($history->bonusCoins > 0 ? [[
                    'type' => GameEventType::GoblinSpadeBonusReceived->value,
                    'player_id' => $player->id,
                    'spades' => $history->spentSpades,
                    'coins' => $history->bonusCoins,
                ]] : []),
                ...($history->incomeStarted ? [[
                    'type' => GameEventType::IncomePhaseStarted->value,
                    'round' => $history->round,
                ]] : []),
            ],
            $stateVersionBefore,
            $stateVersionAfter,
            $phaseChanged,
        );
    }
}
