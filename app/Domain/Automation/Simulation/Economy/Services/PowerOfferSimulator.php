<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\Economy\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Economy\Actions\ApplyPowerOfferDecisionAction;
use App\Domain\GameEngine\Economy\Data\PowerOfferOptionData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class PowerOfferSimulator
{
    public function __construct(private ApplyPowerOfferDecisionAction $applyPowerOfferDecision)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PowerOfferOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $result = $this->applyPowerOfferDecision->execute($simulatedState, $playerId, $option->accept);

        if ($result['advanceTurnCheckpoint']) {
            $simulatedState->round->isCurrentTurnIrrevocable = false;
            $simulatedState->turnStartSnapshot = null;
        }

        return new GameActionSimulationData($simulatedState, $result['nextActivePlayerId']);
    }
}
