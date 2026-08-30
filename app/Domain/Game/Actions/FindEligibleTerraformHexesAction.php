<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\TerrainType;

final class FindEligibleTerraformHexesAction
{
    /** @return list<string> */
    public function execute(GameStateData $state, int $playerId, TerrainType $targetTerrain): array
    {
        $hexesById = collect($state->board->hexes)->keyBy('id');
        $eligibleHexIds = [];

        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $playerId) {
                continue;
            }

            foreach ($hex->adjacentHexIds as $adjacentHexId) {
                $adjacentHex = $hexesById->get($adjacentHexId);

                if ($adjacentHex instanceof BoardHexStateData
                    && $adjacentHex->building === null
                    && $adjacentHex->terrain->isHomeland()
                    && $adjacentHex->terrain !== $targetTerrain) {
                    $eligibleHexIds[] = $adjacentHexId;
                }
            }
        }

        return array_values(array_unique($eligibleHexIds));
    }
}
