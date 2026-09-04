<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\TerrainType;

final class FindReachableLandHexesAction
{
    /** @return list<string> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $hexesById = collect($state->board->hexes)->keyBy('id');
        $reachableHexIds = [];
        $waterFrontier = [];

        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $player->playerId) {
                continue;
            }

            foreach ($hex->adjacentHexIds as $adjacentHexId) {
                $adjacentHex = $hexesById->get($adjacentHexId);

                if ($adjacentHex?->terrain === TerrainType::Water) {
                    $waterFrontier[] = $adjacentHexId;
                } else {
                    $reachableHexIds[] = $adjacentHexId;
                }
            }
        }

        foreach ($state->board->bridges as $bridge) {
            if ($bridge->ownerPlayerId !== $player->playerId) {
                continue;
            }

            $fromHex = $hexesById->get($bridge->fromHexId);
            $toHex = $hexesById->get($bridge->toHexId);

            if ($fromHex?->building?->ownerPlayerId === $player->playerId) {
                $reachableHexIds[] = $bridge->toHexId;
            }

            if ($toHex?->building?->ownerPlayerId === $player->playerId) {
                $reachableHexIds[] = $bridge->fromHexId;
            }
        }

        $visitedWaterHexIds = [];
        $navigationRange = $player->shippingLevel + $player->roundBonus->shippingBonus();

        for ($distance = 1; $distance <= $navigationRange && $waterFrontier !== []; $distance++) {
            $nextWaterFrontier = [];

            foreach (array_unique($waterFrontier) as $waterHexId) {
                if (in_array($waterHexId, $visitedWaterHexIds, true)) {
                    continue;
                }

                $visitedWaterHexIds[] = $waterHexId;
                $waterHex = $hexesById->get($waterHexId);

                if (! $waterHex instanceof BoardHexStateData) {
                    continue;
                }

                foreach ($waterHex->adjacentHexIds as $adjacentHexId) {
                    $adjacentHex = $hexesById->get($adjacentHexId);

                    if ($adjacentHex?->terrain === TerrainType::Water) {
                        $nextWaterFrontier[] = $adjacentHexId;
                    } else {
                        $reachableHexIds[] = $adjacentHexId;
                    }
                }
            }

            $waterFrontier = $nextWaterFrontier;
        }

        return collect($reachableHexIds)->unique()->values()->all();
    }
}
