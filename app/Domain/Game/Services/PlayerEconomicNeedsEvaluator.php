<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\RoundBonus;

final class PlayerEconomicNeedsEvaluator
{
    private const int NEEDED_TOOL_WEIGHT = 20;

    public function __construct(
        private BuildWorkshopOptionFinder $buildWorkshopOptionFinder,
        private UpgradeBuildingOptionFinder $upgradeBuildingOptionFinder,
        private PaidTerraformingOptionFinder $paidTerraformingOptionFinder,
    ) {
    }

    public function execute(GameStateData $before, GameStateData $after, int $playerId): int
    {
        if (! $before->round->phase->isActionPhase() || $before->pendingInteraction !== null) {
            return 0;
        }

        $playerBefore = collect($before->players)->firstWhere('playerId', $playerId);
        $playerAfter = collect($after->players)->firstWhere('playerId', $playerId);
        if (! $playerBefore instanceof GamePlayerStateData || ! $playerAfter instanceof GamePlayerStateData) {
            return 0;
        }

        $neededTools = $this->neededTools($before, $playerBefore);
        if ($neededTools === 0) {
            return 0;
        }

        $gainedTools = max(0, $playerAfter->resources->tools - $playerBefore->resources->tools);
        $incomeBefore = clone $playerBefore;
        $incomeAfter = clone $playerAfter;
        $incomeBefore->roundBonus = RoundBonus::RiverWorkshop;
        $incomeAfter->roundBonus = RoundBonus::RiverWorkshop;
        $gainedIncome = max(
            0,
            PlayerIncomeCalculator::calculate($incomeAfter, $after->board)->tools
            - PlayerIncomeCalculator::calculate($incomeBefore, $before->board)->tools,
        );
        $remainingRounds = max(0, 6 - $before->round->number);

        return min($neededTools, $gainedTools + $gainedIncome * $remainingRounds)
            * self::NEEDED_TOOL_WEIGHT;
    }

    private function neededTools(GameStateData $state, GamePlayerStateData $player): int
    {
        $probe = $state->deepCopy();
        $probe->round->hasTakenMainAction = false;
        $probePlayer = collect($probe->players)->firstWhere('playerId', $player->playerId);
        if (! $probePlayer instanceof GamePlayerStateData) {
            return 0;
        }
        $probePlayer->resources->tools = PHP_INT_MAX;
        $costs = [];
        foreach ($this->buildWorkshopOptionFinder->execute($probe, $probePlayer) as $option) {
            $costs[] = 1;
        }
        foreach ($this->upgradeBuildingOptionFinder->execute($probe, $probePlayer) as $option) {
            $costs[] = $option->tools;
        }
        foreach ($this->paidTerraformingOptionFinder->execute($probe, $probePlayer) as $option) {
            $costs[] = $option->toolCost;
        }

        $missingTools = array_filter(array_map(
            static fn (int $cost): int => $cost - $player->resources->tools,
            $costs,
        ), static fn (int $missing): bool => $missing > 0);

        return $missingTools === [] ? 0 : min($missingTools);
    }
}
