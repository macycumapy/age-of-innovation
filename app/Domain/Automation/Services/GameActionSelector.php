<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Data\EvaluatedGameActionData;
use App\Domain\Automation\Data\GameActionCandidateDiagnosticsData;
use App\Domain\Automation\Data\GameActionSelectionDiagnosticsData;
use App\Domain\Automation\Enums\GameActionSelectionReason;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Economy\Data\ResourceExchangeBatchOptionData;
use App\Domain\GameEngine\Economy\Data\ResourceExchangeOptionData;
use App\Domain\GameEngine\Economy\Data\SacrificePowerOptionData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class GameActionSelector
{
    public function __construct(
        private GameActionRanker $gameActionRanker,
        private GameActionSimulator $gameActionSimulator,
    ) {
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

        $selectedOption = $selected?->option;
        if ($selected !== null) {
            $selected = $this->combineConsecutiveConversions($selected, $playerId);
        }

        return new GameActionSelectionDiagnosticsData(
            selected: $selected,
            candidates: array_map(
                static fn (EvaluatedGameActionData $action, int $index): GameActionCandidateDiagnosticsData => new GameActionCandidateDiagnosticsData(
                    type: $action->option->type(),
                    score: $action->score,
                    rank: $index + 1,
                    scoreDelta: $selected === null ? 0 : $selected->score - $action->score,
                    selected: $action->option === $selectedOption,
                    scoreBreakdown: $action->scoreBreakdown,
                    option: $action->option === $selectedOption ? $selected->option : $action->option,
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

    private function combineConsecutiveConversions(EvaluatedGameActionData $selected, int $playerId): EvaluatedGameActionData
    {
        $option = $selected->option;
        if (! $option instanceof ResourceExchangeOptionData && ! $option instanceof SacrificePowerOptionData) {
            return $selected;
        }
        $amount = $option->amount;
        $exchanges = $option instanceof ResourceExchangeOptionData ? [$option] : [];
        $hasDifferentExchanges = false;
        $simulation = $selected->simulation;
        $combinedCount = 0;
        foreach ($selected->continuation as $next) {
            $matches = ($option instanceof ResourceExchangeOptionData && $next instanceof ResourceExchangeOptionData)
                || ($option instanceof SacrificePowerOptionData && $next instanceof SacrificePowerOptionData);
            if (! $matches) {
                break;
            }
            try {
                $simulation = $this->gameActionSimulator->execute($simulation->state, $playerId, $next);
            } catch (ValidationException|InvalidArgumentException) {
                break;
            }
            $amount += $next->amount;
            if ($option instanceof ResourceExchangeOptionData && $next instanceof ResourceExchangeOptionData) {
                $exchanges[] = $next;
                $hasDifferentExchanges = $hasDifferentExchanges || $option->exchange !== $next->exchange
                    || $option->discipline !== $next->discipline;
            }
            $combinedCount++;
        }
        if ($combinedCount === 0) {
            return $selected;
        }

        $combined = $option instanceof ResourceExchangeOptionData
            ? ($hasDifferentExchanges ? new ResourceExchangeBatchOptionData($exchanges)
                : new ResourceExchangeOptionData($option->exchange, $option->discipline, $amount))
            : new SacrificePowerOptionData($amount);

        return new EvaluatedGameActionData(
            $combined,
            $simulation,
            $selected->score,
            $selected->scoreBreakdown,
            array_slice($selected->continuation, $combinedCount),
        );
    }
}
