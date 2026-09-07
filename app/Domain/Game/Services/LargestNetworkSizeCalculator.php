<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\TerrainType;

final class LargestNetworkSizeCalculator
{
    public static function calculate(
        GamePlayerStateData $player,
        BoardStateData $board,
        bool $includeRoundBonus = true,
    ): int {
        /** @var array<string, BoardHexStateData> $hexesById */
        $hexesById = [];
        /** @var array<string, true> $ownedHexIds */
        $ownedHexIds = [];

        foreach ($board->hexes as $hex) {
            $hexesById[$hex->id] = $hex;

            if ($hex->building?->ownerPlayerId === $player->playerId) {
                $ownedHexIds[$hex->id] = true;
            }
        }

        if ($ownedHexIds === []) {
            return 0;
        }

        /** @var array<string, array<string, true>> $connections */
        $connections = array_fill_keys(array_keys($ownedHexIds), []);

        foreach (array_keys($ownedHexIds) as $hexId) {
            foreach ($hexesById[$hexId]->adjacentHexIds as $adjacentHexId) {
                if (isset($ownedHexIds[$adjacentHexId])) {
                    self::connect($connections, $hexId, $adjacentHexId);
                }
            }
        }

        foreach ($board->bridges as $bridge) {
            if ($bridge->ownerPlayerId === $player->playerId
                && isset($ownedHexIds[$bridge->fromHexId], $ownedHexIds[$bridge->toHexId])) {
                self::connect($connections, $bridge->fromHexId, $bridge->toHexId);
            }
        }

        if ($player->faction === Faction::Moles) {
            self::connectMoleTunnels($connections, $ownedHexIds, $hexesById);
        }

        $navigationRange = $player->shippingLevel
            + ($includeRoundBonus ? $player->roundBonus->shippingBonus() : 0);

        if ($navigationRange > 0) {
            foreach (array_keys($ownedHexIds) as $hexId) {
                self::connectThroughWater($connections, $hexId, $navigationRange, $ownedHexIds, $hexesById);
            }
        }

        return self::largestComponentSize($connections);
    }

    /** @param array<string, array<string, true>> $connections */
    private static function connect(array &$connections, string $firstHexId, string $secondHexId): void
    {
        $connections[$firstHexId][$secondHexId] = true;
        $connections[$secondHexId][$firstHexId] = true;
    }

    /**
     * @param array<string, array<string, true>> $connections
     * @param array<string, true> $ownedHexIds
     * @param array<string, BoardHexStateData> $hexesById
     */
    private static function connectMoleTunnels(
        array &$connections,
        array $ownedHexIds,
        array $hexesById,
    ): void {
        foreach (array_keys($ownedHexIds) as $fromHexId) {
            $fromHex = $hexesById[$fromHexId];

            foreach (array_keys($ownedHexIds) as $toHexId) {
                $toHex = $hexesById[$toHexId];
                $qDistance = $toHex->q - $fromHex->q;
                $rDistance = $toHex->r - $fromHex->r;
                $hexDistance = max(abs($qDistance), abs($rDistance), abs($qDistance + $rDistance));
                $hasIntermediateHex = array_intersect(
                    $fromHex->adjacentHexIds,
                    $toHex->adjacentHexIds,
                    array_keys($hexesById),
                ) !== [];

                if ($hexDistance === 2 && $hasIntermediateHex) {
                    self::connect($connections, $fromHexId, $toHexId);
                }
            }
        }
    }

    /**
     * @param array<string, array<string, true>> $connections
     * @param array<string, true> $ownedHexIds
     * @param array<string, BoardHexStateData> $hexesById
     */
    private static function connectThroughWater(
        array &$connections,
        string $originHexId,
        int $navigationRange,
        array $ownedHexIds,
        array $hexesById,
    ): void {
        $waterFrontier = array_values(array_filter(
            $hexesById[$originHexId]->adjacentHexIds,
            static fn (string $hexId): bool => ($hexesById[$hexId] ?? null)?->terrain === TerrainType::Water,
        ));
        /** @var array<string, true> $visitedWaterHexIds */
        $visitedWaterHexIds = [];

        for ($distance = 1; $distance <= $navigationRange && $waterFrontier !== []; $distance++) {
            $nextWaterFrontier = [];

            foreach (array_unique($waterFrontier) as $waterHexId) {
                if (isset($visitedWaterHexIds[$waterHexId])) {
                    continue;
                }

                $visitedWaterHexIds[$waterHexId] = true;
                $waterHex = $hexesById[$waterHexId] ?? null;

                if (! $waterHex instanceof BoardHexStateData) {
                    continue;
                }

                foreach ($waterHex->adjacentHexIds as $adjacentHexId) {
                    if (isset($ownedHexIds[$adjacentHexId]) && $adjacentHexId !== $originHexId) {
                        self::connect($connections, $originHexId, $adjacentHexId);
                    } elseif (($hexesById[$adjacentHexId] ?? null)?->terrain === TerrainType::Water) {
                        $nextWaterFrontier[] = $adjacentHexId;
                    }
                }
            }

            $waterFrontier = $nextWaterFrontier;
        }
    }

    /** @param array<string, array<string, true>> $connections */
    private static function largestComponentSize(array $connections): int
    {
        /** @var array<string, true> $visitedHexIds */
        $visitedHexIds = [];
        $largestSize = 0;

        foreach (array_keys($connections) as $startingHexId) {
            if (isset($visitedHexIds[$startingHexId])) {
                continue;
            }

            $componentSize = 0;
            $frontier = [$startingHexId];

            while ($frontier !== []) {
                $hexId = array_pop($frontier);

                if ($hexId === null || isset($visitedHexIds[$hexId])) {
                    continue;
                }

                $visitedHexIds[$hexId] = true;
                $componentSize++;

                foreach (array_keys($connections[$hexId]) as $connectedHexId) {
                    $frontier[] = $connectedHexId;
                }
            }

            $largestSize = max($largestSize, $componentSize);
        }

        return $largestSize;
    }
}
