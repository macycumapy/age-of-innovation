<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\UpgradeBuildingOptionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionAvailabilityReason;

final class UpgradeBuildingOptionFinder
{
    /**
     * @param list<GameActionAvailabilityReason> $reasons
     * @return list<UpgradeBuildingOptionData>
     */
    public function execute(GameStateData $state, GamePlayerStateData $player, array &$reasons = []): array
    {
        $reasons = [];
        if ($state->pendingInteraction !== null || $state->round->hasTakenMainAction) {
            $reasons[] = GameActionAvailabilityReason::ActionUnavailable;
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
                if ($player->resources->tools < $cost['tools']) {
                    $reasons[] = GameActionAvailabilityReason::InsufficientTools;
                }
                if ($player->resources->coins < $cost['coins']) {
                    $reasons[] = GameActionAvailabilityReason::InsufficientCoins;
                }
                if ($this->buildingCount($state, $player->playerId, $target) >= $target->supplyLimit()) {
                    $reasons[] = GameActionAvailabilityReason::SupplyLimitReached;
                }

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

        $reasons = $options !== [] ? [] : array_values(array_unique($reasons, SORT_REGULAR));
        if ($options === [] && $reasons === []) {
            $reasons[] = GameActionAvailabilityReason::NoEligibleTarget;
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
