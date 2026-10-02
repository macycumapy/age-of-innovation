<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Board\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Board\Actions\ApplyBuildWorkshopAction;
use App\Domain\GameEngine\Board\Data\BuildWorkshopOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class BuildWorkshopSimulator
{
    public function __construct(private ApplyBuildWorkshopAction $applyBuildWorkshop)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        BuildWorkshopOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyBuildWorkshop->execute($simulatedState, $simulatedPlayer, $option->hexId);

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
