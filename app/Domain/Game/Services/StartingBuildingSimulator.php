<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyStartingBuildingAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\StartingBuildingOptionData;
use InvalidArgumentException;

final class StartingBuildingSimulator
{
    public function __construct(private ApplyStartingBuildingAction $applyStartingBuilding)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        StartingBuildingOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyStartingBuilding->execute($simulatedState, $player, $option->hexId);

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
