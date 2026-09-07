<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BridgeStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\TerrainType;
use Illuminate\Support\Collection;

final class FindEligibleBridgePairsAction
{
    /** @var list<array{int, int}> */
    private const array NEIGHBOUR_OFFSETS = [
        [1, 0], [1, -1], [0, -1],
        [-1, 0], [-1, 1], [0, 1],
    ];

    /** @var list<array{int, int}> */
    private const array BRIDGE_OFFSETS = [
        [1, 1], [-1, -1],
        [2, -1], [-2, 1],
        [1, -2], [-1, 2],
    ];

    /** @return list<array{fromHexId: string, toHexId: string}> */
    public function execute(GameStateData $state, int $playerId, bool $canBuildAcrossTerrain = false): array
    {
        $hexesById = collect($state->board->hexes)->keyBy('id');
        $pairs = [];

        foreach ($state->board->hexes as $fromHex) {
            if ($fromHex->building?->ownerPlayerId !== $playerId || $fromHex->building->isNeutral) {
                continue;
            }

            foreach (self::BRIDGE_OFFSETS as [$qOffset, $rOffset]) {
                $toHexId = ($fromHex->q + $qOffset).':'.($fromHex->r + $rOffset);
                $toHex = $hexesById->get($toHexId);

                if (! $toHex instanceof BoardHexStateData
                    || ! $toHex->terrain->isHomeland()
                    || ($canBuildAcrossTerrain
                        ? ! $this->hasTerrainBesideBridge($fromHex, $toHex, $hexesById)
                        : (! in_array($fromHex->id, $state->board->riverBankHexIds, true)
                            || ! in_array($toHex->id, $state->board->riverBankHexIds, true)
                            || ! $this->hasWaterBetweenFacingCorners($fromHex, $toHex, $hexesById)))
                    || $this->bridgeExists($state->board->bridges, $fromHex->id, $toHex->id)) {
                    continue;
                }

                $pairs[] = [
                    'fromHexId' => $fromHex->id,
                    'toHexId' => $toHex->id,
                ];
            }
        }

        return $pairs;
    }

    /** @param Collection<string, BoardHexStateData> $hexesById */
    private function hasWaterBetweenFacingCorners(
        BoardHexStateData $fromHex,
        BoardHexStateData $toHex,
        Collection $hexesById,
    ): bool {
        $betweenHexIds = $this->betweenHexIds($fromHex, $toHex);

        return count($betweenHexIds) === 2
            && collect($betweenHexIds)->every(
                static fn (string $hexId): bool => $hexesById->get($hexId)?->terrain === TerrainType::Water,
            );
    }

    /** @param Collection<string, BoardHexStateData> $hexesById */
    private function hasTerrainBesideBridge(
        BoardHexStateData $fromHex,
        BoardHexStateData $toHex,
        Collection $hexesById,
    ): bool {
        $betweenHexIds = $this->betweenHexIds($fromHex, $toHex);

        return count($betweenHexIds) === 2
            && collect($betweenHexIds)->contains(
                static fn (string $hexId): bool => $hexesById->get($hexId)?->terrain->isHomeland() === true,
            );
    }

    /** @return list<string> */
    private function betweenHexIds(BoardHexStateData $fromHex, BoardHexStateData $toHex): array
    {
        $fromNeighbours = array_map(
            static fn (array $offset): string => ($fromHex->q + $offset[0]).':'.($fromHex->r + $offset[1]),
            self::NEIGHBOUR_OFFSETS,
        );
        $toNeighbours = array_map(
            static fn (array $offset): string => ($toHex->q + $offset[0]).':'.($toHex->r + $offset[1]),
            self::NEIGHBOUR_OFFSETS,
        );

        return array_values(array_intersect($fromNeighbours, $toNeighbours));
    }

    /** @param list<BridgeStateData> $bridges */
    private function bridgeExists(array $bridges, string $fromHexId, string $toHexId): bool
    {
        return collect($bridges)->contains(
            static fn (BridgeStateData $bridge): bool => ($bridge->fromHexId === $fromHexId && $bridge->toHexId === $toHexId)
                || ($bridge->fromHexId === $toHexId && $bridge->toHexId === $fromHexId),
        );
    }
}
