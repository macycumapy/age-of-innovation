<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Services;

use App\Domain\GameEngine\Board\Actions\FindReachableLandHexesAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildWorkshopOptionData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Interactions\Enums\GameActionAvailabilityReason;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

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
