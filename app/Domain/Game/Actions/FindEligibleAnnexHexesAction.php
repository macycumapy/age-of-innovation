<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GameStateData;

final class FindEligibleAnnexHexesAction
{
    /** @return list<string> */
    public function execute(GameStateData $state, int $playerId): array
    {
        return array_values(array_map(
            static fn (BoardHexStateData $hex): string => $hex->id,
            array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerId
                    && ! $hex->building->hasAnnex,
            ),
        ));
    }
}
