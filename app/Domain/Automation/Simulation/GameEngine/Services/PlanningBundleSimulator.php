<?php

declare(strict_types=1);

namespace App\Domain\Automation\Simulation\GameEngine\Services;

use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\GameEngine\Setup\Actions\ApplyPlanningBundleAction;
use App\Domain\GameEngine\Setup\Data\PlanningBundleOptionData;
use App\Domain\GameEngine\State\Data\GameStateData;

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
