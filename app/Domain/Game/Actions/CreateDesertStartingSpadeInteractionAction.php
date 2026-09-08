<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TerrainType;

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
