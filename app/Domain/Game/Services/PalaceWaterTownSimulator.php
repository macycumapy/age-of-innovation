<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyPalaceWaterTownDecisionAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PalaceWaterTownOptionData;

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
        $simulatedState = GameStateData::from($state->toArray());
        $result = $this->applyPalaceWaterTownDecision->execute(
            $simulatedState,
            $playerId,
            $option->accept,
            $option->waterHexId,
        );

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
