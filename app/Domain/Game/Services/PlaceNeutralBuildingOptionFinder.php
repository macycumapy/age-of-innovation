<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\FindEligibleNeutralBuildingHexesAction;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlaceNeutralBuildingOptionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\PendingInteractionType;

final class PlaceNeutralBuildingOptionFinder
{
    public function __construct(private FindEligibleNeutralBuildingHexesAction $findEligibleHexes)
    {
    }

    /** @return list<PlaceNeutralBuildingOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $interaction = $state->pendingInteraction;
        $buildingType = BuildingType::tryFrom((string) ($interaction?->context['buildingType'] ?? ''));
        if ($interaction?->type !== PendingInteractionType::PlaceNeutralBuilding
            || $interaction->playerId !== $player->playerId
            || ($interaction->context['reason'] ?? null) === 'starting_competency'
            || $buildingType === null) {
            return [];
        }

        return array_map(
            static fn (string $hexId): PlaceNeutralBuildingOptionData => new PlaceNeutralBuildingOptionData(
                $hexId,
                $buildingType,
            ),
            $this->findEligibleHexes->execute($state, $player),
        );
    }
}
