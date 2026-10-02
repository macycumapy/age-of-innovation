<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class FindReachableLandHexesAction
{
    /** @return list<string> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $hexesById = [];
        foreach ($state->board->hexes as $hex) {
            $hexesById[$hex->id] = $hex;
        }
        $reachableHexIds = [];
        $waterFrontier = [];

        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $player->playerId) {
                continue;
            }

            foreach ($hex->adjacentHexIds as $adjacentHexId) {
                $adjacentHex = $hexesById[$adjacentHexId] ?? null;

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

            $fromHex = $hexesById[$bridge->fromHexId] ?? null;
            $toHex = $hexesById[$bridge->toHexId] ?? null;

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
                $waterHex = $hexesById[$waterHexId] ?? null;

                if (! $waterHex instanceof BoardHexStateData) {
                    continue;
                }

                foreach ($waterHex->adjacentHexIds as $adjacentHexId) {
                    $adjacentHex = $hexesById[$adjacentHexId] ?? null;

                    if ($adjacentHex?->terrain === TerrainType::Water) {
                        $nextWaterFrontier[] = $adjacentHexId;
                    } else {
                        $reachableHexIds[] = $adjacentHexId;
                    }
                }
            }

            $waterFrontier = $nextWaterFrontier;
        }

        return array_values(array_unique($reachableHexIds));
    }
}
