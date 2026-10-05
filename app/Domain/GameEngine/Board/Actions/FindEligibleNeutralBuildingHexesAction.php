<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class FindEligibleNeutralBuildingHexesAction
{
    public function __construct(private FindReachableLandHexesAction $findReachableLandHexes)
    {
    }

    /** @return list<string> */
    public function execute(GameStateData $state, GamePlayerStateData $player, bool $ignoreResourceCost = false): array
    {
        $reachableHexIds = $this->findReachableLandHexes->execute($state, $player);
        $toolCostPerSpade = max(1, 3 - $player->terraformingLevel);

        return collect($state->board->hexes)
            ->filter(static fn (BoardHexStateData $hex): bool => in_array($hex->id, $reachableHexIds, true)
                && $hex->building === null
                && $hex->terrain->isHomeland()
                && ($ignoreResourceCost || $hex->terrain->spadesTo($player->homeland) * $toolCostPerSpade <= $player->resources->tools))
            ->pluck('id')
            ->values()
            ->all();
    }
}
