<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Actions;

use App\Domain\GameEngine\Board\Actions\FindEligibleTerraformHexesAction;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

class CreateDesertStartingSpadeInteractionAction
{
    public function __construct(private FindEligibleTerraformHexesAction $findEligibleTerraformHexes)
    {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        bool $chooseStartingCompetencyAfterSpade = false,
    ): bool {
        if ($player->homeland !== TerrainType::Desert || $player->unassignedSpades < 1) {
            return false;
        }

        $eligibleHexIds = $this->findEligibleTerraformHexes->execute($state, $player, TerrainType::Desert);

        if ($eligibleHexIds === []) {
            return false;
        }

        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            $player->playerId,
            $eligibleHexIds,
            [
                'spadeCount' => 1,
                'remainingSpades' => 1,
                'targetTerrain' => TerrainType::Desert->value,
                'resumeStartingBuildingPlacement' => true,
                'chooseStartingCompetencyAfterSpade' => $chooseStartingCompetencyAfterSpade,
            ],
        );

        return true;
    }
}
