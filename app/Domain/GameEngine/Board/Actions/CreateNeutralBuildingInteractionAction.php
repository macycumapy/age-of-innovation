<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

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

        if ($eligibleHexIds === [] && $this->findEligibleHexes->execute($state, $playerState, ignoreResourceCost: true) === []) {
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
