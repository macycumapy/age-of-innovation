<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\ApplyPlanningBundleAction;
use App\Domain\Game\Data\GameActionSimulationData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlanningBundleOptionData;

final class PlanningBundleSimulator
{
    public function __construct(private ApplyPlanningBundleAction $applyPlanningBundle)
    {
    }

    public function execute(
        GameStateData $state,
        int $playerId,
        PlanningBundleOptionData $option,
    ): GameActionSimulationData {
        $simulatedState = $state->deepCopy();
        $result = $this->applyPlanningBundle->execute($simulatedState, $playerId, null, $option->homeland);

        return new GameActionSimulationData($simulatedState, $result->nextActivePlayerId);
    }
}
