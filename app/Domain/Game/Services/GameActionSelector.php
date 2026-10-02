<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\EvaluatedGameActionData;
use App\Domain\Game\Data\GameActionCandidateDiagnosticsData;
use App\Domain\Game\Data\GameActionSelectionDiagnosticsData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\GameActionSelectionReason;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Enums\GamePhase;

final class GameActionSelector
{
    public function __construct(private GameActionRanker $gameActionRanker)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        GameBotDifficulty $difficulty = GameBotDifficulty::Balanced,
        int $auxiliaryActionsRemaining = 1,
    ): ?EvaluatedGameActionData {
        return $this->selectWithDiagnostics(
            $state,
            $playerId,
            $difficulty,
            $auxiliaryActionsRemaining,
        )->selected;
    }

    public function selectWithDiagnostics(
        GameStateData $state,
        int $playerId,
        GameBotDifficulty $difficulty = GameBotDifficulty::Balanced,
        int $auxiliaryActionsRemaining = 1,
    ): GameActionSelectionDiagnosticsData {
        $parameters = $difficulty->searchParameters();
        $startedAt = hrtime(true);

        $rankedActions = $this->gameActionRanker->execute(
            $state,
            $playerId,
            depth: $state->round->phase === GamePhase::Setup ? 1 : $parameters['depth'],
            branchLimit: $parameters['branchLimit'],
            maxNodes: $parameters['maxNodes'],
            maxTimeMilliseconds: $parameters['maxTimeMilliseconds'],
            auxiliaryActionsRemaining: $auxiliaryActionsRemaining,
        );

        $selected = $rankedActions[0] ?? null;

        return new GameActionSelectionDiagnosticsData(
            selected: $selected,
            candidates: array_map(
                static fn (EvaluatedGameActionData $action, int $index): GameActionCandidateDiagnosticsData => new GameActionCandidateDiagnosticsData(
                    type: $action->option->type(),
                    score: $action->score,
                    rank: $index + 1,
                    scoreDelta: $selected === null ? 0 : $selected->score - $action->score,
                    selected: $index === 0,
                    scoreBreakdown: $action->scoreBreakdown,
                ),
                $rankedActions,
                array_keys($rankedActions),
            ),
            selectionReason: match (count($rankedActions)) {
                0 => GameActionSelectionReason::NoLegalActions,
                1 => GameActionSelectionReason::OnlyLegalAction,
                default => GameActionSelectionReason::HighestScore,
            },
            visitedNodes: $this->gameActionRanker->lastVisitedNodes(),
            durationMilliseconds: (int) ((hrtime(true) - $startedAt) / 1_000_000),
            budgetExhausted: $this->gameActionRanker->lastBudgetExhausted(),
            searchTimings: $this->gameActionRanker->lastSearchTimings(),
        );
    }
}
