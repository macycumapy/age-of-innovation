<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Services;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Setup\Data\StartingBuildingOptionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

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
