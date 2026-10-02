<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Board\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Board\Actions\ApplyPlaceNeutralBuildingAction;
use App\Domain\GameEngine\Board\Data\PlaceNeutralBuildingOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class PlaceNeutralBuildingSimulator
{
    public function __construct(private ApplyPlaceNeutralBuildingAction $applyPlaceNeutralBuilding)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PlaceNeutralBuildingOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $player = collect($simulatedState->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyPlaceNeutralBuilding->execute(
            $simulatedState,
            $player,
            $option->hexId,
            $option->buildingType,
        );

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
