<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyPowerOfferDecisionAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PowerOfferOptionData;

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
