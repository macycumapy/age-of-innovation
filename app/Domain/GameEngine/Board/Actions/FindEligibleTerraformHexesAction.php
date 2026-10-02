<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

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
        $hexesById = [];
        foreach ($state->board->hexes as $hex) {
            $hexesById[$hex->id] = $hex;
        }
        $reachableHexIds = $this->findReachableLandHexes->execute($state, $player);
        $eligibleHexIds = [];
        foreach ($reachableHexIds as $hexId) {
            $hex = $hexesById[$hexId] ?? null;
            if ($hex instanceof BoardHexStateData
                && $hex->building === null
                && $hex->terrain->isHomeland()
                && $hex->terrain !== $targetTerrain) {
                $eligibleHexIds[] = $hexId;
            }
        }

        return $eligibleHexIds;
    }
}
