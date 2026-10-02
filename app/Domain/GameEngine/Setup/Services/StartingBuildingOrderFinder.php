<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Services;

use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\Setup\Data\PlayerPlanningSelectionData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class StartingBuildingOrderFinder
{
    /** @return list<int> */
    public function execute(GameStateData $state): array
    {
        $factionsByPlayerId = collect($state->planningSelections)
            ->mapWithKeys(static fn (PlayerPlanningSelectionData $selection): array => [
                $selection->playerId => $selection->bundle->faction,
            ]);
        $orderedPlayerIds = array_values(array_filter(
            $state->turnOrder,
            static fn (int $playerId): bool => $factionsByPlayerId->has($playerId),
        ));
        $monkPlayerIds = array_values(array_filter(
            $orderedPlayerIds,
            static fn (int $playerId): bool => $factionsByPlayerId->get($playerId) === Faction::Monks,
        ));
        $omarPlayerIds = array_values(array_filter(
            $orderedPlayerIds,
            static fn (int $playerId): bool => $factionsByPlayerId->get($playerId) === Faction::Omar,
        ));
        $regularPlayerIds = array_values(array_diff($orderedPlayerIds, $monkPlayerIds));

        return [
            ...$regularPlayerIds,
            ...array_reverse($regularPlayerIds),
            ...$omarPlayerIds,
            ...$monkPlayerIds,
        ];
    }
}
