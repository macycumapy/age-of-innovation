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
    public function execute(GameStateData $state, int $playerId): array
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
                    || ! in_array($fromHex->id, $state->board->riverBankHexIds, true)
                    || ! in_array($toHex->id, $state->board->riverBankHexIds, true)
                    || ! $toHex->terrain->isHomeland()
                    || ! $this->hasWaterBetweenFacingCorners($fromHex, $toHex, $hexesById)
                    || $this->bridgeExists($state->board->bridges, $fromHex->id, $toHex->id)) {
                    continue;
                }

                $pairIds = [$fromHex->id, $toHex->id];
                sort($pairIds);
                $pairs[implode('|', $pairIds)] = [
                    'fromHexId' => $fromHex->id,
                    'toHexId' => $toHex->id,
                ];
            }
        }

        return array_values($pairs);
    }

    /** @param Collection<string, BoardHexStateData> $hexesById */
    private function hasWaterBetweenFacingCorners(
        BoardHexStateData $fromHex,
        BoardHexStateData $toHex,
        Collection $hexesById,
    ): bool {
        $fromNeighbours = array_map(
            static fn (array $offset): string => ($fromHex->q + $offset[0]).':'.($fromHex->r + $offset[1]),
            self::NEIGHBOUR_OFFSETS,
        );
        $toNeighbours = array_map(
            static fn (array $offset): string => ($toHex->q + $offset[0]).':'.($toHex->r + $offset[1]),
            self::NEIGHBOUR_OFFSETS,
        );
        $betweenHexIds = array_values(array_intersect($fromNeighbours, $toNeighbours));

        return count($betweenHexIds) === 2
            && collect($betweenHexIds)->every(
                static fn (string $hexId): bool => $hexesById->get($hexId)?->terrain === TerrainType::Water,
            );
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
