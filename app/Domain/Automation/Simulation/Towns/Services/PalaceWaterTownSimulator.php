<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Towns\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Actions\ApplyPalaceWaterTownDecisionAction;
use App\Domain\GameEngine\Towns\Data\PalaceWaterTownOptionData;

final class PalaceWaterTownSimulator
{
    public function __construct(private ApplyPalaceWaterTownDecisionAction $applyPalaceWaterTownDecision)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PalaceWaterTownOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $result = $this->applyPalaceWaterTownDecision->execute(
            $simulatedState,
            $playerId,
            $option->accept,
            $option->waterHexId,
        );

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
