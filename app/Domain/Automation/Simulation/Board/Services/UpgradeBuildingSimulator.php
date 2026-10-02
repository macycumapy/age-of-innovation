<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Board\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Board\Actions\ApplyUpgradeBuildingAction;
use App\Domain\GameEngine\Board\Data\UpgradeBuildingOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class UpgradeBuildingSimulator
{
    public function __construct(private ApplyUpgradeBuildingAction $applyUpgradeBuilding)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        UpgradeBuildingOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyUpgradeBuilding->execute(
            $simulatedState,
            $simulatedPlayer,
            $option->hexId,
            $option->target,
        );

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
