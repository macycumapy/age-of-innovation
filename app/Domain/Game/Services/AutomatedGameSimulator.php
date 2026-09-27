<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\PlayAutomatedTurnAction;
use App\Domain\Game\Data\AutomatedGameDecisionData;
use App\Domain\Game\Data\AutomatedGameResourcesData;
use App\Domain\Game\Data\AutomatedGameSimulationResultData;
use App\Domain\Game\Data\GameActionSelectionDiagnosticsData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use DomainException;

class AutomatedGameSimulator
{
    public function __construct(private PlayAutomatedTurnAction $playAutomatedTurn)
    {
    }

    public function execute(
        Game $game,
        int $maxDecisions = 2_000,
        int $maxDurationMilliseconds = 300_000,
    ): AutomatedGameSimulationResultData {
        if ($maxDecisions < 1 || $maxDurationMilliseconds < 1) {
            throw new DomainException('Лимиты симуляции должны быть положительными.');
        }

        if ($game->players()->whereNull('bot_difficulty')->exists()) {
            throw new DomainException('В автоматической симуляции все участники должны быть ботами.');
        }

        $startedAt = hrtime(true);
        $decisions = [];
        $stoppedReason = null;

        while ($game->refresh()->status === GameStatus::Active) {
            if (count($decisions) >= $maxDecisions) {
                $stoppedReason = 'decision_limit';

                break;
            }

            if ($this->elapsedMilliseconds($startedAt) >= $maxDurationMilliseconds) {
                $stoppedReason = 'time_limit';

                break;
            }

            $player = $game->activeGamePlayer()->first();

            if (! $player instanceof GamePlayer || $player->bot_difficulty === null) {
                throw new DomainException('Не найден активный автоматический игрок.');
            }

            $versionBefore = $game->version;
            $round = $game->state->round->number;
            $phase = $game->state->round->phase;
            $playerState = collect($game->state->players)->firstWhere('playerId', $player->id);
            $decisionStartedAt = hrtime(true);
            $diagnostics = null;

            $this->playAutomatedTurn->execute(
                $game,
                $player,
                $player->bot_difficulty,
                singleDecision: true,
                onDecisionSelected: static function (GameActionSelectionDiagnosticsData $selection) use (&$diagnostics): void {
                    $diagnostics = $selection;
                },
            );
            $game->refresh();

            if ($game->version <= $versionBefore) {
                throw new DomainException('Автоматическое решение не изменило состояние партии.');
            }

            $action = $game->actions()
                ->where('game_player_id', $player->id)
                ->where('state_version_before', '>=', $versionBefore)
                ->latest('sequence')
                ->first();
            $actionType = $action?->type;
            $selectedScore = null;
            $candidates = [];
            $visitedNodes = 0;
            $durationMilliseconds = $this->elapsedMilliseconds($decisionStartedAt);
            $budgetExhausted = false;

            if ($diagnostics instanceof GameActionSelectionDiagnosticsData) {
                $selectedScore = $diagnostics->selected?->score;
                $candidates = $diagnostics->candidates;
                $visitedNodes = $diagnostics->visitedNodes;
                $durationMilliseconds = $diagnostics->durationMilliseconds;
                $budgetExhausted = $diagnostics->budgetExhausted;
            }

            $decisions[] = new AutomatedGameDecisionData(
                gamePlayerId: $player->id,
                round: $round,
                phase: $phase,
                actionType: $actionType,
                selectedScore: $selectedScore,
                candidates: $candidates,
                visitedNodes: $visitedNodes,
                durationMilliseconds: $durationMilliseconds,
                budgetExhausted: $budgetExhausted,
                passReason: $actionType === GameActionType::Pass ? 'selected_pass' : null,
                remainingResources: $actionType === GameActionType::Pass && $playerState !== null
                    ? new AutomatedGameResourcesData(
                        coins: $playerState->resources->coins,
                        tools: $playerState->resources->tools,
                        scholars: $playerState->resources->scholars,
                        books: array_sum($playerState->resources->books->toArray()),
                        power: array_sum($playerState->resources->power->toArray()),
                        spades: $playerState->unassignedSpades,
                    )
                    : null,
            );
        }

        $game->refresh();

        return new AutomatedGameSimulationResultData(
            completed: $game->status === GameStatus::Finished,
            decisions: $decisions,
            durationMilliseconds: $this->elapsedMilliseconds($startedAt),
            finalScores: collect($game->state->players)->mapWithKeys(
                static fn ($player): array => [$player->playerId => $player->victoryPoints],
            )->all(),
            stoppedReason: $stoppedReason,
        );
    }

    private function elapsedMilliseconds(int $startedAt): int
    {
        return (int) ((hrtime(true) - $startedAt) / 1_000_000);
    }
}
