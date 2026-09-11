<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\PalaceAbility;

final class FindEligiblePalaceFlightHexesAction
{
    /** @return list<string> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if ($player->palaceId !== PalaceAbility::Palace09->value || $player->resources->scholars < 1) {
            return [];
        }

        $ownedBuildingHexes = collect($state->board->hexes)->filter(
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId,
        );
        $ownedBuildingHexIds = $ownedBuildingHexes->pluck('id')->all();

        return collect($state->board->hexes)
            ->filter(static fn (BoardHexStateData $targetHex): bool => $targetHex->building === null
                && $targetHex->terrain->isHomeland()
                && $targetHex->terrain !== $player->homeland
                && ! collect($targetHex->adjacentHexIds)->contains(
                    static fn (string $adjacentHexId): bool => in_array($adjacentHexId, $ownedBuildingHexIds, true),
                )
                && $ownedBuildingHexes->contains(
                    static fn (BoardHexStateData $originHex): bool => self::hexDistance($originHex, $targetHex) <= 3,
                ))
            ->pluck('id')
            ->unique()
            ->values()
            ->all();
    }

    private static function hexDistance(BoardHexStateData $firstHex, BoardHexStateData $secondHex): int
    {
        $qDistance = $secondHex->q - $firstHex->q;
        $rDistance = $secondHex->r - $firstHex->r;

        return max(abs($qDistance), abs($rDistance), abs($qDistance + $rDistance));
    }
}
