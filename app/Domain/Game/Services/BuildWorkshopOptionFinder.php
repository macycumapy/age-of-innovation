<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\FindReachableLandHexesAction;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildWorkshopOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionAvailabilityReason;

final class BuildWorkshopOptionFinder
{
    public function __construct(private FindReachableLandHexesAction $findReachableLandHexes)
    {
    }

    /**
     * @param list<GameActionAvailabilityReason> $reasons
     * @return list<BuildWorkshopOptionData>
     */
    public function execute(GameStateData $state, GamePlayerStateData $player, array &$reasons = []): array
    {
        $reasons = [];
        $workshopCount = count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                && $hex->building->type === BuildingType::Workshop
                && ! $hex->building->isNeutral,
        ));

        if ($state->pendingInteraction !== null || $state->round->hasTakenMainAction) {
            $reasons[] = GameActionAvailabilityReason::ActionUnavailable;
        }
        if ($player->resources->tools < 1) {
            $reasons[] = GameActionAvailabilityReason::InsufficientTools;
        }
        if ($player->resources->coins < 2) {
            $reasons[] = GameActionAvailabilityReason::InsufficientCoins;
        }
        if ($workshopCount >= BuildingType::Workshop->supplyLimit()) {
            $reasons[] = GameActionAvailabilityReason::SupplyLimitReached;
        }
        if ($reasons !== []) {
            return [];
        }

        $reachableHexIds = $this->findReachableLandHexes->execute($state, $player);

        $options = array_values(array_map(
            static fn (BoardHexStateData $hex): BuildWorkshopOptionData => new BuildWorkshopOptionData($hex->id),
            array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $hex): bool => in_array($hex->id, $reachableHexIds, true)
                    && $hex->building === null
                    && $hex->terrain === $player->homeland,
            ),
        ));
        if ($options === []) {
            $reasons[] = GameActionAvailabilityReason::NoReachableTarget;
        }

        return $options;
    }
}
