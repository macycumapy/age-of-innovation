<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyFinishActionTurnAction;
use App\Domain\Game\Data\EvaluatedGameActionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\GameTreeSearchContext;
use InvalidArgumentException;

final class GameActionRanker
{
    public function __construct(
        private GameActionOptionFinder $gameActionOptionFinder,
        private GameActionSimulator $gameActionSimulator,
        private GameStateEvaluator $gameStateEvaluator,
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
    ): array {
        if ($depth < 1 || $branchLimit < 1 || $maxNodes < 1) {
            throw new InvalidArgumentException('Глубина, ширина и бюджет поиска должны быть положительными.');
        }

        $rankedActions = [];

        foreach ($this->gameActionOptionFinder->execute($state, $playerId) as $index => $option) {
            $simulation = $this->gameActionSimulator->execute($state, $playerId, $option);
            $rankedActions[] = [
                'evaluation' => new EvaluatedGameActionData(
                    $option,
                    $simulation,
                    $this->search(
                        $simulation,
                        $playerId,
                        $depth - 1,
                        $branchLimit,
                        new GameTreeSearchContext($maxNodes),
                        PHP_INT_MIN,
                        PHP_INT_MAX,
                    ),
                ),
                'index' => $index,
            ];
        }

        usort(
            $rankedActions,
            static fn (array $left, array $right): int => $right['evaluation']->score <=> $left['evaluation']->score
                ?: $left['index'] <=> $right['index'],
        );

        return array_column($rankedActions, 'evaluation');
    }

    private function search(
        GameActionSimulationData $simulation,
        int $rootPlayerId,
        int $remainingDepth,
        int $branchLimit,
        GameTreeSearchContext $context,
        int $alpha,
        int $beta,
    ): int {
        $state = $simulation->state;
        $nextActivePlayerId = $simulation->nextActivePlayerId;

        if ($state->pendingInteraction === null && $state->round->hasTakenMainAction) {
            $currentPlayer = $this->playerById($state, $nextActivePlayerId);

            if ($currentPlayer === null) {
                return $this->gameStateEvaluator->execute($state, $rootPlayerId);
            }

            $nextPlayerId = $this->applyFinishActionTurn->execute($state, $currentPlayer);
            $nextPlayer = collect($state->players)->firstWhere('playerId', $nextPlayerId);
            $nextActivePlayerId = $nextPlayer instanceof GamePlayerStateData ? $nextPlayer->playerId : null;
        }

        if ($remainingDepth === 0) {
            return $this->gameStateEvaluator->execute($state, $rootPlayerId);
        }

        $cacheKey = $this->cacheKey($state, $nextActivePlayerId, $rootPlayerId, $remainingDepth);
        if (isset($context->cachedScores[$cacheKey])) {
            return $context->cachedScores[$cacheKey];
        }

        if ($context->visitedNodes >= $context->maxNodes) {
            return $this->gameStateEvaluator->execute($state, $rootPlayerId);
        }

        $context->visitedNodes++;

        $activePlayer = $this->playerById($state, $nextActivePlayerId);
        if ($activePlayer === null) {
            return $this->gameStateEvaluator->execute($state, $rootPlayerId);
        }

        $options = array_slice(
            $this->gameActionOptionFinder->execute($state, $activePlayer->playerId),
            0,
            $branchLimit,
        );
        $simulations = [];
        foreach ($options as $option) {
            $nextSimulation = $this->gameActionSimulator->execute($state, $activePlayer->playerId, $option);
            $simulations[] = [
                'simulation' => $nextSimulation,
                'score' => $this->gameStateEvaluator->execute($nextSimulation->state, $rootPlayerId),
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
                $remainingDepth - 1,
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

    private function cacheKey(
        GameStateData $state,
        ?int $nextActivePlayerId,
        int $rootPlayerId,
        int $remainingDepth,
    ): string {
        return hash('xxh128', json_encode([
            'state' => $state->toArray(),
            'nextActivePlayerId' => $nextActivePlayerId,
            'rootPlayerId' => $rootPlayerId,
            'remainingDepth' => $remainingDepth,
        ], JSON_THROW_ON_ERROR));
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
