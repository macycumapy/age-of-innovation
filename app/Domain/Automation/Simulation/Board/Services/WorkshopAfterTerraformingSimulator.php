<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Board\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Board\Actions\ApplyWorkshopAfterTerraformingAction;
use App\Domain\GameEngine\Board\Data\WorkshopAfterTerraformingOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use InvalidArgumentException;

final class WorkshopAfterTerraformingSimulator
{
    public function __construct(private ApplyWorkshopAfterTerraformingAction $applyWorkshopAfterTerraforming)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        WorkshopAfterTerraformingOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $simulatedPlayer = collect($simulatedState->players)->firstWhere('playerId', $playerId);

        if (! $simulatedPlayer instanceof GamePlayerStateData) {
            throw new InvalidArgumentException('Не найдено состояние игрока для симуляции.');
        }

        $result = $this->applyWorkshopAfterTerraforming->execute(
            $simulatedState,
            $simulatedPlayer,
            $option->build,
            $option->hexId,
        );

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
