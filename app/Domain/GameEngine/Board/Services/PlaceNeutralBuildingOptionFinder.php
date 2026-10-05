<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Services;

use App\Domain\GameEngine\Board\Actions\FindEligibleNeutralBuildingHexesAction;
use App\Domain\GameEngine\Board\Data\PlaceNeutralBuildingOptionData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

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

        return [...array_map(
            static fn (string $hexId): PlaceNeutralBuildingOptionData => new PlaceNeutralBuildingOptionData(
                $hexId,
                $buildingType,
            ),
            $this->findEligibleHexes->execute($state, $player),
        ), new PlaceNeutralBuildingOptionData(null, $buildingType)];
    }
}
