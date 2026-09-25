<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\StartingBuildingOptionData;
use App\Domain\Game\Enums\GamePhase;

final class StartingBuildingOptionFinder
{
    /** @return list<StartingBuildingOptionData> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        if ($state->round->phase !== GamePhase::Setup
            || count($state->planningSelections) !== count($state->turnOrder)
            || $state->pendingInteraction !== null
            || $state->pendingStartingBuildingHexId !== null) {
            return [];
        }

        return array_values(array_map(
            static fn (BoardHexStateData $hex): StartingBuildingOptionData => new StartingBuildingOptionData($hex->id),
            array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $hex): bool => $hex->building === null && $hex->terrain === $player->homeland,
            ),
        ));
    }
}
