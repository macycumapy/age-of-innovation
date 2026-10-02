<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class FindEligibleMoleTunnelHexesAction
{
    /** @return list<string> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if ($player->faction !== Faction::Moles) {
            return [];
        }

        $hexesById = collect($state->board->hexes)->keyBy('id');
        $ownedBuildingHexIds = collect($state->board->hexes)
            ->filter(static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId)
            ->pluck('id')
            ->all();
        $eligibleHexIds = [];

        foreach ($ownedBuildingHexIds as $originHexId) {
            $originHex = $hexesById->get($originHexId);

            if (! $originHex instanceof BoardHexStateData) {
                continue;
            }

            foreach ($state->board->hexes as $targetHex) {
                if (self::hexDistance($originHex, $targetHex) !== 2
                    || ! self::hasIntermediateHex($originHex, $targetHex, $hexesById->keys()->all())
                    || $targetHex->building !== null
                    || ! $targetHex->terrain->isHomeland()
                    || $targetHex->terrain === $player->homeland
                    || collect($targetHex->adjacentHexIds)->contains(
                        static fn (string $adjacentHexId): bool => in_array($adjacentHexId, $ownedBuildingHexIds, true),
                    )) {
                    continue;
                }

                $eligibleHexIds[] = $targetHex->id;
            }
        }

        return array_values(array_unique($eligibleHexIds));
    }

    private static function hexDistance(BoardHexStateData $firstHex, BoardHexStateData $secondHex): int
    {
        $qDistance = $secondHex->q - $firstHex->q;
        $rDistance = $secondHex->r - $firstHex->r;

        return max(abs($qDistance), abs($rDistance), abs($qDistance + $rDistance));
    }

    /** @param list<string> $existingHexIds */
    private static function hasIntermediateHex(
        BoardHexStateData $originHex,
        BoardHexStateData $targetHex,
        array $existingHexIds,
    ): bool {
        return array_intersect($originHex->adjacentHexIds, $targetHex->adjacentHexIds, $existingHexIds) !== [];
    }
}
