<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Data\EvaluatedGameActionData;
use App\Domain\Automation\Data\GameActionCandidateDiagnosticsData;
use App\Domain\Automation\Data\GameActionSelectionDiagnosticsData;
use App\Domain\Automation\Enums\GameActionSelectionReason;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class GameActionSelector
{
    public function __construct(private GameActionRanker $gameActionRanker)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        GameBotDifficulty $difficulty = GameBotDifficulty::Balanced,
        int $auxiliaryActionsRemaining = 3,
    ): ?EvaluatedGameActionData {
        return $this->selectWithDiagnostics(
            $state,
            $playerId,
            $difficulty,
            $auxiliaryActionsRemaining,
        )->selected;
    }

    /** @param list<GameActionOption> $plannedOptions */
    public function selectWithDiagnostics(
        GameStateData $state,
        int $playerId,
        GameBotDifficulty $difficulty = GameBotDifficulty::Balanced,
        int $auxiliaryActionsRemaining = 3,
        array $plannedOptions = [],
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
        $plannedOption = $plannedOptions[0] ?? null;
        $isPlannedContinuation = false;
        if ($plannedOption !== null && $state->pendingInteraction === null) {
            foreach ($rankedActions as $action) {
                if ($action->option->type() === $plannedOption->type() && $action->option->toArray() === $plannedOption->toArray()) {
                    $selected = new EvaluatedGameActionData(
                        $action->option,
                        $action->simulation,
                        $action->score,
                        $action->scoreBreakdown,
                        array_slice($plannedOptions, 1),
                    );
                    $isPlannedContinuation = true;
                    break;
                }
            }
        }

        return new GameActionSelectionDiagnosticsData(
            selected: $selected,
            candidates: array_map(
                static fn (EvaluatedGameActionData $action, int $index): GameActionCandidateDiagnosticsData => new GameActionCandidateDiagnosticsData(
                    type: $action->option->type(),
                    score: $action->score,
                    rank: $index + 1,
                    scoreDelta: $selected === null ? 0 : $selected->score - $action->score,
                    selected: $selected !== null && $action->option === $selected->option,
                    scoreBreakdown: $action->scoreBreakdown,
                    option: $action->option,
                ),
                $rankedActions,
                array_keys($rankedActions),
            ),
            selectionReason: $isPlannedContinuation ? GameActionSelectionReason::PlannedContinuation : match (count($rankedActions)) {
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
