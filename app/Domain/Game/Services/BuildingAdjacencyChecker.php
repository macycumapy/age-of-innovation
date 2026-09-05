<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;

final class BuildingAdjacencyChecker
{
    public static function hasOpponent(BoardStateData $board, BoardHexStateData $hex, int $playerId): bool
    {
        $neighborHexIds = self::neighborHexIds($board, $hex);

        return collect($board->hexes)->contains(
            static fn (BoardHexStateData $candidate): bool => in_array($candidate->id, $neighborHexIds, true)
                && $candidate->building !== null
                && $candidate->building->ownerPlayerId !== $playerId,
        );
    }

    /** @return list<string> */
    public static function neighborHexIds(BoardStateData $board, BoardHexStateData $hex): array
    {
        $neighborHexIds = $hex->adjacentHexIds;

        foreach ($board->bridges as $bridge) {
            if ($bridge->fromHexId === $hex->id) {
                $neighborHexIds[] = $bridge->toHexId;
            } elseif ($bridge->toHexId === $hex->id) {
                $neighborHexIds[] = $bridge->fromHexId;
            }
        }

        return array_values(array_unique($neighborHexIds));
    }
}
