<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

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
        $ownedBuildingHexIds = $ownedBuildingHexes->map(static fn (BoardHexStateData $hex): string => $hex->id)->all();

        return array_values(collect($state->board->hexes)
            ->filter(static fn (BoardHexStateData $targetHex): bool => $targetHex->building === null
                && $targetHex->terrain->isHomeland()
                && $targetHex->terrain !== $player->homeland
                && ! collect($targetHex->adjacentHexIds)->contains(
                    static fn (string $adjacentHexId): bool => in_array($adjacentHexId, $ownedBuildingHexIds, true),
                )
                && $ownedBuildingHexes->contains(
                    static fn (BoardHexStateData $originHex): bool => self::hexDistance($originHex, $targetHex) <= 3,
                ))
            ->map(static fn (BoardHexStateData $hex): string => $hex->id)
            ->unique()
            ->values()
            ->all());
    }

    private static function hexDistance(BoardHexStateData $firstHex, BoardHexStateData $secondHex): int
    {
        $qDistance = $secondHex->q - $firstHex->q;
        $rDistance = $secondHex->r - $firstHex->r;

        return max(abs($qDistance), abs($rDistance), abs($qDistance + $rDistance));
    }
}
