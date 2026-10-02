<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Actions;

use App\Domain\GameEngine\Setup\Services\StartingBuildingOrderFinder;
use App\Models\Game;

final class DetermineStartingBuildingOrderAction
{
    public function __construct(private StartingBuildingOrderFinder $startingBuildingOrderFinder)
    {
    }

    /** @return list<int> */
    public function execute(Game $game): array
    {
        return $this->startingBuildingOrderFinder->execute($game->state);
    }
}
