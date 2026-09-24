<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyFinishActionTurnAction;
use App\Domain\Game\Data\EvaluatedGameActionData;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
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
    ): array {
        if ($depth < 1 || $branchLimit < 1) {
            throw new InvalidArgumentException('Глубина и ширина поиска должны быть положительными.');
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
        int $alpha,
        int $beta,
    ): int {
        $state = $simulation->state;
        $nextActiveUserId = $simulation->nextActiveUserId;

        if ($state->pendingInteraction === null && $state->round->hasTakenMainAction) {
            $currentPlayer = $this->playerByUserId($state, $nextActiveUserId);

            if ($currentPlayer === null) {
                return $this->gameStateEvaluator->execute($state, $rootPlayerId);
            }

            $nextActiveUserId = $this->applyFinishActionTurn->execute($state, $currentPlayer);
        }

        if ($remainingDepth === 0) {
            return $this->gameStateEvaluator->execute($state, $rootPlayerId);
        }

        $activePlayer = $this->playerByUserId($state, $nextActiveUserId);
        if ($activePlayer === null) {
            return $this->gameStateEvaluator->execute($state, $rootPlayerId);
        }

        $simulations = [];
        foreach ($this->gameActionOptionFinder->execute($state, $activePlayer->playerId) as $option) {
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
        $simulations = array_slice($simulations, 0, $branchLimit);
        $bestScore = $maximizing ? PHP_INT_MIN : PHP_INT_MAX;

        foreach ($simulations as $candidate) {
            $score = $this->search(
                $candidate['simulation'],
                $rootPlayerId,
                $remainingDepth - 1,
                $branchLimit,
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
                break;
            }
        }

        return $bestScore;
    }

    private function playerByUserId(GameStateData $state, ?int $userId): ?GamePlayerStateData
    {
        if ($userId === null) {
            return null;
        }

        $player = collect($state->players)->firstWhere('userId', $userId);

        return $player instanceof GamePlayerStateData ? $player : null;
    }
}
