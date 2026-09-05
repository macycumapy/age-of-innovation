<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;

final class FindEligibleNeutralBuildingHexesAction
{
    public function __construct(private FindReachableLandHexesAction $findReachableLandHexes)
    {
    }

    /** @return list<string> */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $reachableHexIds = $this->findReachableLandHexes->execute($state, $player);
        $toolCostPerSpade = max(1, 3 - $player->terraformingLevel);

        return collect($state->board->hexes)
            ->filter(static fn (BoardHexStateData $hex): bool => in_array($hex->id, $reachableHexIds, true)
                && $hex->building === null
                && $hex->terrain->isHomeland()
                && $hex->terrain->spadesTo($player->homeland) * $toolCostPerSpade <= $player->resources->tools)
            ->pluck('id')
            ->values()
            ->all();
    }
}
