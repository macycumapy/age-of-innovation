<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlanningBundleOptionData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
use App\Domain\Game\Enums\GamePhase;

final class PlanningBundleOptionFinder
{
    /** @return list<PlanningBundleOptionData> */
    public function execute(GameStateData $state, int $playerId): array
    {
        if ($state->round->phase !== GamePhase::Setup
            || $state->setupPool === null
            || $state->pendingInteraction !== null
            || ! in_array($playerId, $state->turnOrder, true)
            || collect($state->players)->contains('playerId', $playerId)) {
            return [];
        }

        $selectedHomelands = array_map(
            static fn (PlayerPlanningSelectionData $selection) => $selection->bundle->homeland,
            $state->planningSelections,
        );

        return array_values(array_map(
            static fn (PlanningBundleData $bundle): PlanningBundleOptionData => new PlanningBundleOptionData($bundle->homeland),
            array_filter(
                $state->setupPool->planningBundles,
                static fn (PlanningBundleData $bundle): bool => ! in_array($bundle->homeland, $selectedHomelands, true),
            ),
        ));
    }
}
