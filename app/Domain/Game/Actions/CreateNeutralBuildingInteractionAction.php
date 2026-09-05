<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\PendingInteractionType;

final class CreateNeutralBuildingInteractionAction
{
    public function __construct(private FindEligibleNeutralBuildingHexesAction $findEligibleHexes)
    {
    }

    /** @param array<string, mixed> $context */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $playerState,
        BuildingType $buildingType,
        array $context,
    ): bool {
        $eligibleHexIds = $this->findEligibleHexes->execute($state, $playerState);

        if ($eligibleHexIds === []) {
            return false;
        }

        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::PlaceNeutralBuilding,
            $playerState->playerId,
            $eligibleHexIds,
            [...$context, 'buildingType' => $buildingType->value],
        );

        return true;
    }
}
