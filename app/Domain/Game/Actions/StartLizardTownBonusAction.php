<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;

final class StartLizardTownBonusAction
{
    public function __construct(
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $playerState): bool
    {
        $eligibleHexIds = $this->findEligibleTerraformHexes->execute(
            $state,
            $playerState,
            $playerState->homeland,
        );

        if ($eligibleHexIds === []) {
            $state->pendingInteraction = null;

            return false;
        }

        $playerState->unassignedSpades++;
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            $playerState->playerId,
            $eligibleHexIds,
            [
                'phase' => GamePhase::Actions->value,
                'spadeCount' => 1,
                'remainingSpades' => 1,
                'targetTerrain' => $playerState->homeland->value,
                'lizardFreeWorkshop' => true,
            ],
        );

        return true;
    }
}
