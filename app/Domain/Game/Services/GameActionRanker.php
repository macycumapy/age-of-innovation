<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyFinishActionTurnAction;
use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Data\EvaluatedGameActionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\GameTreeSearchContext;
use App\Domain\Game\Enums\GameActionOptionType;
use InvalidArgumentException;

final class GameActionRanker
{
    private int $lastVisitedNodes = 0;

    private bool $lastBudgetExhausted = false;

    private const int MAX_AUXILIARY_ACTIONS_PER_TURN = 1;

    private const int ROUND_SCORING_PRIORITY_WEIGHT = 10;

    private const int FINAL_SCORING_PRIORITY_WEIGHT = 10;

    public function __construct(
        private GameActionOptionFinder $gameActionOptionFinder,
        private GameActionSimulator $gameActionSimulator,
        private GameStateEvaluator $gameStateEvaluator,
        private RoundScoringProgressEvaluator $roundScoringProgressEvaluator,
        private FinalScoringProgressEvaluator $finalScoringProgressEvaluator,
        private BoardPositionProgressEvaluator $boardPositionProgressEvaluator,
        private ApplyFinishActionTurnAction $applyFinishActionTurn,
    ) {
    }

    /** @return list<EvaluatedGameActionData> */
    public function execute(
        GameStateData $state,
        int $playerId,
        int $depth = 1,
        int $branchLimit = 8,
        int $maxNodes = 1000,
        int $maxTimeMilliseconds = 20_000,
        int $auxiliaryActionsRemaining = self::MAX_AUXILIARY_ACTIONS_PER_TURN,
    ): array {
        if ($depth < 1
            || $branchLimit < 1
            || $maxNodes < 1
            || $maxTimeMilliseconds < 1
            || $auxiliaryActionsRemaining < 0
        ) {
            throw new InvalidArgumentException('Глубина, ширина и бюджеты поиска должны быть положительными.');
        }

        $context = new GameTreeSearchContext($maxNodes, $maxTimeMilliseconds);
        $candidates = [];

        $options = $this->gameActionOptionFinder->execute($state, $playerId);
        if ($auxiliaryActionsRemaining === 0) {
            $options = array_values(array_filter(
                $options,
                fn (GameActionOption $option): bool => ! $this->isAuxiliaryOption($option),
            ));
        }

        foreach ($options as $index => $option) {
            if ($candidates !== [] && $context->isExhausted()) {
                break;
            }

            $simulation = $this->gameActionSimulator->execute($state, $playerId, $option);
            $candidates[] = [
                'option' => $option,
                'simulation' => $simulation,
                'score' => $this->gameStateEvaluator->execute($simulation->state, $playerId)
                    + $this->strategicProgress($state, $simulation->state, $playerId),
                'index' => $index,
                'isPass' => $option->type() === GameActionOptionType::Pass,
            ];
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score']
                ?: $left['isPass'] <=> $right['isPass']
                ?: $left['index'] <=> $right['index'],
        );

        $rankedActions = [];

        foreach ($candidates as $candidate) {
            $option = $candidate['option'];
            $simulation = $candidate['simulation'];
            $rankedActions[] = [
                'evaluation' => new EvaluatedGameActionData(
                    $option,
                    $simulation,
                    $this->search(
                        $simulation,
                        $playerId,
                        $this->remainingDepthAfter($state, $option, $depth),
                        $this->auxiliaryActionsAfter($option, $auxiliaryActionsRemaining),
                        $branchLimit,
                        $context,
                        PHP_INT_MIN,
                        PHP_INT_MAX,
                    ) + $this->strategicProgress($state, $simulation->state, $playerId),
                ),
                'index' => $candidate['index'],
                'isAuxiliary' => $this->isAuxiliaryOption($option),
                'isPass' => $option->type() === GameActionOptionType::Pass,
            ];
        }

        usort(
            $rankedActions,
            static fn (array $left, array $right): int => $right['evaluation']->score <=> $left['evaluation']->score
                ?: $left['isPass'] <=> $right['isPass']
                ?: $left['isAuxiliary'] <=> $right['isAuxiliary']
                ?: $left['index'] <=> $right['index'],
        );

        $this->lastVisitedNodes = $context->visitedNodes;
        $this->lastBudgetExhausted = $context->isExhausted();

        return array_column($rankedActions, 'evaluation');
    }

    public function lastVisitedNodes(): int
    {
        return $this->lastVisitedNodes;
    }

    public function lastBudgetExhausted(): bool
    {
        return $this->lastBudgetExhausted;
    }

    private function search(
        GameActionSimulationData $simulation,
        int $rootPlayerId,
        int $remainingDepth,
        int $auxiliaryActionsRemaining,
        int $branchLimit,
        GameTreeSearchContext $context,
        int $alpha,
        int $beta,
    ): int {
        $state = $simulation->state;
        $nextActivePlayerId = $simulation->nextActivePlayerId;

        if ($state->round->phase->isActionPhase()
            && $state->pendingInteraction === null
            && $state->round->hasTakenMainAction) {
            $currentPlayer = $this->playerById($state, $nextActivePlayerId);

            if ($currentPlayer === null) {
                return $this->gameStateEvaluator->execute($state, $rootPlayerId);
            }

            $nextPlayerId = $this->applyFinishActionTurn->execute($state, $currentPlayer);
            $nextPlayer = collect($state->players)->firstWhere('playerId', $nextPlayerId);
            $nextActivePlayerId = $nextPlayer instanceof GamePlayerStateData ? $nextPlayer->playerId : null;
            $auxiliaryActionsRemaining = self::MAX_AUXILIARY_ACTIONS_PER_TURN;
        }

        if ($remainingDepth === 0) {
            return $this->gameStateEvaluator->execute($state, $rootPlayerId);
        }

        $cacheKey = $this->cacheKey(
            $state,
            $nextActivePlayerId,
            $rootPlayerId,
            $remainingDepth,
            $auxiliaryActionsRemaining,
        );
        if (isset($context->cachedScores[$cacheKey])) {
            return $context->cachedScores[$cacheKey];
        }

        if ($context->isExhausted()) {
            return $this->gameStateEvaluator->execute($state, $rootPlayerId);
        }

        $context->visitedNodes++;

        $activePlayer = $this->playerById($state, $nextActivePlayerId);
        if ($activePlayer === null) {
            return $this->gameStateEvaluator->execute($state, $rootPlayerId);
        }

        $options = $this->gameActionOptionFinder->execute($state, $activePlayer->playerId);
        if ($auxiliaryActionsRemaining === 0) {
            $options = array_values(array_filter(
                $options,
                fn (GameActionOption $option): bool => ! $this->isAuxiliaryOption($option),
            ));
        }
        $options = array_slice($options, 0, $branchLimit);
        $simulations = [];
        foreach ($options as $option) {
            if ($simulations !== [] && $context->isExhausted()) {
                break;
            }

            $nextSimulation = $this->gameActionSimulator->execute($state, $activePlayer->playerId, $option);
            $simulations[] = [
                'simulation' => $nextSimulation,
                'score' => $this->gameStateEvaluator->execute($nextSimulation->state, $rootPlayerId),
                'remainingDepth' => $this->remainingDepthAfter($state, $option, $remainingDepth),
                'auxiliaryActionsRemaining' => $this->auxiliaryActionsAfter($option, $auxiliaryActionsRemaining),
            ];
        }

        if ($simulations === []) {
            return $this->gameStateEvaluator->execute($state, $rootPlayerId);
        }

        $maximizing = $activePlayer->playerId === $rootPlayerId;
        usort(
            $simulations,
            static fn (array $left, array $right): int => $maximizing
                ? $right['score'] <=> $left['score']
                : $left['score'] <=> $right['score'],
        );
        $bestScore = $maximizing ? PHP_INT_MIN : PHP_INT_MAX;
        $wasCutOff = false;

        foreach ($simulations as $candidate) {
            $score = $this->search(
                $candidate['simulation'],
                $rootPlayerId,
                $candidate['remainingDepth'],
                $candidate['auxiliaryActionsRemaining'],
                $branchLimit,
                $context,
                $alpha,
                $beta,
            );

            if ($maximizing) {
                $bestScore = max($bestScore, $score);
                $alpha = max($alpha, $score);
            } else {
                $bestScore = min($bestScore, $score);
                $beta = min($beta, $score);
            }

            if ($beta <= $alpha) {
                $wasCutOff = true;

                break;
            }
        }

        if (! $wasCutOff) {
            $context->cachedScores[$cacheKey] = $bestScore;
        }

        return $bestScore;
    }

    private function remainingDepthAfter(
        GameStateData $state,
        GameActionOption $option,
        int $remainingDepth,
    ): int {
        if ($state->pendingInteraction !== null
            || in_array($option->type(), [
                GameActionOptionType::ExchangeResources,
                GameActionOptionType::SacrificePower,
            ], true)) {
            return $remainingDepth;
        }

        return max(0, $remainingDepth - 1);
    }

    private function auxiliaryActionsAfter(GameActionOption $option, int $remaining): int
    {
        return $this->isAuxiliaryOption($option) ? max(0, $remaining - 1) : $remaining;
    }

    private function strategicProgress(GameStateData $before, GameStateData $after, int $playerId): int
    {
        return $this->roundScoringProgressEvaluator->execute($before, $after, $playerId)
                * self::ROUND_SCORING_PRIORITY_WEIGHT
            + $this->finalScoringProgressEvaluator->execute($before, $after, $playerId)
                * self::FINAL_SCORING_PRIORITY_WEIGHT
            + $this->boardPositionProgressEvaluator->execute($before, $after, $playerId);
    }

    private function isAuxiliaryOption(GameActionOption $option): bool
    {
        return in_array($option->type(), [
            GameActionOptionType::ExchangeResources,
            GameActionOptionType::SacrificePower,
        ], true);
    }

    private function cacheKey(
        GameStateData $state,
        ?int $nextActivePlayerId,
        int $rootPlayerId,
        int $remainingDepth,
        int $auxiliaryActionsRemaining,
    ): string {
        return hash('xxh128', serialize([
            'state' => $state,
            'nextActivePlayerId' => $nextActivePlayerId,
            'rootPlayerId' => $rootPlayerId,
            'remainingDepth' => $remainingDepth,
            'auxiliaryActionsRemaining' => $auxiliaryActionsRemaining,
        ]));
    }

    private function playerById(GameStateData $state, ?int $playerId): ?GamePlayerStateData
    {
        if ($playerId === null) {
            return null;
        }

        $player = collect($state->players)->firstWhere('playerId', $playerId);

        return $player instanceof GamePlayerStateData ? $player : null;
    }
}
