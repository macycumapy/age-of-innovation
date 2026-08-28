<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\Faction;
use App\Models\Game;

final class DetermineStartingBuildingOrderAction
{
    /** @return list<int> */
    public function execute(Game $game): array
    {
        $playersById = $game->players()->get()->keyBy('id');
        $orderedPlayerIds = array_values(array_filter(
            $game->state->turnOrder,
            static fn (int $playerId): bool => $playersById->has($playerId),
        ));
        $monkPlayerIds = array_values(array_filter(
            $orderedPlayerIds,
            static fn (int $playerId): bool => $playersById->get($playerId)?->faction === Faction::Monks,
        ));
        $regularPlayerIds = array_values(array_diff($orderedPlayerIds, $monkPlayerIds));

        return [
            ...$regularPlayerIds,
            ...array_reverse($regularPlayerIds),
            ...$monkPlayerIds,
        ];
    }
}
