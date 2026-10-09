<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\Automation\Evaluation\Data\TownAnnexPlanningData;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class AnnexOpportunityEvaluator
{
    public function __construct(
        private OwnedBuildingComponentFinder $componentFinder,
        private TownAnnexPlanningEvaluator $townPlanner,
    ) {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player): TownAnnexPlanningData
    {
        $components = array_values(array_filter($this->componentFinder->execute($state, $player->playerId), static fn (array $component): bool =>
            ! array_any($component, static fn (BoardHexStateData $hex): bool => $hex->townId !== null)));

        return $this->townPlanner->execute($state, $player, $components, array_fill(0, count($components), 0));
    }
}
