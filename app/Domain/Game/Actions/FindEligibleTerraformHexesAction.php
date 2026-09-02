<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\TerrainType;

final class FindEligibleTerraformHexesAction
{
    public function __construct(private FindReachableLandHexesAction $findReachableLandHexes)
    {
    }

    /** @return list<string> */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        TerrainType $targetTerrain,
    ): array {
        $hexesById = collect($state->board->hexes)->keyBy('id');
        $reachableHexIds = $this->findReachableLandHexes->execute($state, $player);
        return collect($reachableHexIds)
            ->unique()
            ->filter(function (string $hexId) use ($hexesById, $targetTerrain): bool {
                $hex = $hexesById->get($hexId);

                return $hex instanceof BoardHexStateData
                    && $hex->building === null
                    && $hex->terrain->isHomeland()
                    && $hex->terrain !== $targetTerrain;
            })
            ->values()
            ->all();
    }
}
