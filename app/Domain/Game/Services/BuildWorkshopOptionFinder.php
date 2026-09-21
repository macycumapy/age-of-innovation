<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\FindReachableLandHexesAction;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildWorkshopOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\BuildingType;

final class BuildWorkshopOptionFinder
{
    public function __construct(private FindReachableLandHexesAction $findReachableLandHexes)
    {
    }

    /** @return list<BuildWorkshopOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $workshopCount = count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                && $hex->building->type === BuildingType::Workshop
                && ! $hex->building->isNeutral,
        ));

        if ($state->pendingInteraction !== null
            || $state->round->hasTakenMainAction
            || $player->resources->tools < 1
            || $player->resources->coins < 2
            || $workshopCount >= BuildingType::Workshop->supplyLimit()) {
            return [];
        }

        $reachableHexIds = $this->findReachableLandHexes->execute($state, $player);

        return array_values(array_map(
            static fn (BoardHexStateData $hex): BuildWorkshopOptionData => new BuildWorkshopOptionData($hex->id),
            array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $hex): bool => in_array($hex->id, $reachableHexIds, true)
                    && $hex->building === null
                    && $hex->terrain === $player->homeland,
            ),
        ));
    }
}
