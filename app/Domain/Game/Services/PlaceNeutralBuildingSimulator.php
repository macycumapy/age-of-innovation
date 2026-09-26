<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyPlaceNeutralBuildingAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlaceNeutralBuildingOptionData;
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
