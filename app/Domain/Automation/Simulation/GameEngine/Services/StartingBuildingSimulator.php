<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\GameEngine\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Setup\Actions\ApplyStartingBuildingAction;
use App\Domain\GameEngine\Setup\Data\StartingBuildingOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
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
