<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\EvaluatedGameActionData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\GameBotDifficulty;

final class GameActionSelector
{
    public function __construct(private GameActionRanker $gameActionRanker)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        GameBotDifficulty $difficulty = GameBotDifficulty::Balanced,
    ): ?EvaluatedGameActionData {
        $parameters = $difficulty->searchParameters();

        return $this->gameActionRanker->execute(
            $state,
            $playerId,
            depth: $parameters['depth'],
            branchLimit: $parameters['branchLimit'],
            maxNodes: $parameters['maxNodes'],
        )[0] ?? null;
    }
}
