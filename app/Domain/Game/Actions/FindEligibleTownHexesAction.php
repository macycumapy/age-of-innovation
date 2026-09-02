<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\TerrainType;

final class FindEligibleTownHexesAction
{
    /** @return list<string> */
    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        string $builtHexId,
        ?string $ignoredWaterHexId = null,
    ): array {
        $hexesById = collect($state->board->hexes)->keyBy('id');
        $start = $hexesById->get($builtHexId);

        if (! $start instanceof BoardHexStateData
            || $start->building?->ownerPlayerId !== $player->playerId
            || $start->townId !== null) {
            return [];
        }

        $bridgeConnections = [];

        foreach ($state->board->bridges as $bridge) {
            if ($bridge->ownerPlayerId !== $player->playerId) {
                continue;
            }

            $bridgeConnections[$bridge->fromHexId][] = $bridge->toHexId;
            $bridgeConnections[$bridge->toHexId][] = $bridge->fromHexId;
        }

        $componentIds = [];
        $visitedHexIds = [];
        $frontier = [$builtHexId];

        while ($frontier !== []) {
            $hexId = array_shift($frontier);

            if (! is_string($hexId) || in_array($hexId, $visitedHexIds, true)) {
                continue;
            }

            $visitedHexIds[] = $hexId;
            $hex = $hexesById->get($hexId);

            if ($hex instanceof BoardHexStateData
                && $hex->id === $ignoredWaterHexId
                && $hex->terrain === TerrainType::Water
                && $hex->building === null
                && $hex->townId === null) {
                $frontier = [...$frontier, ...$hex->adjacentHexIds];

                continue;
            }

            if (! $hex instanceof BoardHexStateData
                || $hex->building?->ownerPlayerId !== $player->playerId
                || $hex->townId !== null) {
                continue;
            }

            $componentIds[] = $hexId;
            $frontier = [
                ...$frontier,
                ...$hex->adjacentHexIds,
                ...($bridgeConnections[$hexId] ?? []),
            ];
        }

        $component = array_values(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => in_array($hex->id, $componentIds, true),
        ));
        $annexCount = count(array_filter(
            $component,
            static fn (BoardHexStateData $hex): bool => $hex->building?->hasAnnex === true,
        ));
        $minimumHexCount = 4;

        if (collect($component)->contains(
            static fn (BoardHexStateData $hex): bool => $hex->building?->type === BuildingType::University,
        )) {
            $minimumHexCount = 3;
        }

        if (collect($component)->contains(
            static fn (BoardHexStateData $hex): bool => $hex->building?->type === BuildingType::Monument,
        )) {
            $minimumHexCount = 2;
        }

        $minimumHexCount = max(1, $minimumHexCount - $annexCount);
        $requiredPower = $player->palaceId === PalaceAbility::Palace08->value ? 6 : 7;
        $power = array_sum(array_map(
            static fn (BoardHexStateData $hex): int => ($hex->building?->type->powerValue() ?? 0)
                + ($hex->building?->hasAnnex === true ? 1 : 0),
            $component,
        ));

        return count($component) >= $minimumHexCount && $power >= $requiredPower
            ? $componentIds
            : [];
    }
}
