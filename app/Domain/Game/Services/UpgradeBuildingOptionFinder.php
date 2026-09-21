<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\UpgradeBuildingOptionData;
use App\Domain\Game\Enums\BuildingType;

final class UpgradeBuildingOptionFinder
{
    /** @return list<UpgradeBuildingOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if ($state->pendingInteraction !== null || $state->round->hasTakenMainAction) {
            return [];
        }

        $options = [];

        foreach ($state->board->hexes as $hex) {
            if ($hex->building === null
                || $hex->building->ownerPlayerId !== $player->playerId
                || $hex->building->isNeutral) {
                continue;
            }

            $hasAdjacentOpponent = BuildingAdjacencyChecker::hasOpponent($state->board, $hex, $player->playerId);

            foreach ($hex->building->type->upgradeOptions() as $target) {
                $cost = $hex->building->type->upgradeCostTo($target, $hasAdjacentOpponent);

                if ($player->resources->tools < $cost['tools']
                    || $player->resources->coins < $cost['coins']
                    || $this->buildingCount($state, $player->playerId, $target) >= $target->supplyLimit()) {
                    continue;
                }

                $options[] = new UpgradeBuildingOptionData(
                    $hex->id,
                    $hex->building->type,
                    $target,
                    $cost['tools'],
                    $cost['coins'],
                );
            }
        }

        return $options;
    }

    private function buildingCount(GameStateData $state, int $playerId, BuildingType $type): int
    {
        return count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerId
                && $hex->building->type === $type
                && ! $hex->building->isNeutral,
        ));
    }
}
