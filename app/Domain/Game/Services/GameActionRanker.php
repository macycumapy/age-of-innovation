<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\EvaluatedGameActionData;
use App\Domain\Game\Data\GameStateData;

final class GameActionRanker
{
    public function __construct(
        private GameActionOptionFinder $gameActionOptionFinder,
        private GameActionSimulator $gameActionSimulator,
        private GameStateEvaluator $gameStateEvaluator,
    ) {
    }

    /** @return list<EvaluatedGameActionData> */
    public function execute(GameStateData $state, int $playerId): array
    {
        $rankedActions = [];

        foreach ($this->gameActionOptionFinder->execute($state, $playerId) as $index => $option) {
            $simulation = $this->gameActionSimulator->execute($state, $playerId, $option);
            $rankedActions[] = [
                'evaluation' => new EvaluatedGameActionData(
                    $option,
                    $simulation,
                    $this->gameStateEvaluator->execute($simulation->state, $playerId),
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
}
