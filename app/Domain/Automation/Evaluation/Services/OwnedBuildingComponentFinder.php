<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class OwnedBuildingComponentFinder
{
    /** @return list<list<BoardHexStateData>> */
    public function execute(GameStateData $state, int $playerId): array
    {
        $owned = collect($state->board->hexes)->filter(static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $playerId)->keyBy('id')->all();
        $bridges = [];
        foreach ($state->board->bridges as $bridge) {
            if ($bridge->ownerPlayerId === $playerId) {
                $bridges[$bridge->fromHexId][] = $bridge->toHexId;
                $bridges[$bridge->toHexId][] = $bridge->fromHexId;
            }
        }
        $components = [];
        $visited = [];
        foreach (array_keys($owned) as $origin) {
            if (isset($visited[$origin])) {
                continue;
            }
            $component = [];
            $frontier = [$origin];
            while ($frontier !== []) {
                $id = array_pop($frontier);
                if (isset($visited[$id]) || ! isset($owned[$id])) {
                    continue;
                }
                $visited[$id] = true;
                $hex = $owned[$id];
                $component[] = $hex;
                $frontier = [...$frontier, ...$hex->adjacentHexIds, ...($bridges[$id] ?? [])];
            }
            $components[] = $component;
        }

        return $components;
    }
}
