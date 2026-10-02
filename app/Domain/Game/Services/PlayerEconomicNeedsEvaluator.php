<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\RoundBonus;

final class PlayerEconomicNeedsEvaluator
{
    private const int NEEDED_TOOL_WEIGHT = 20;

    private const int USEFUL_DEVELOPMENT_WEIGHT = 15;

    private const int MAX_SCHOLAR_BONUS = 120;

    public function __construct(
        private BuildWorkshopOptionFinder $buildWorkshopOptionFinder,
        private UpgradeBuildingOptionFinder $upgradeBuildingOptionFinder,
        private PaidTerraformingOptionFinder $paidTerraformingOptionFinder,
        private DevelopmentAdvancementOptionFinder $developmentAdvancementOptionFinder,
        private BoardPositionProgressEvaluator $boardPositionProgressEvaluator,
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
        $scholarScore = $this->scholarScore($before, $after, $playerBefore, $playerAfter);

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
            * self::NEEDED_TOOL_WEIGHT + $scholarScore;
    }

    private function scholarScore(
        GameStateData $before,
        GameStateData $after,
        GamePlayerStateData $playerBefore,
        GamePlayerStateData $playerAfter,
    ): int {
        $scholarsBefore = $playerBefore->resources->scholars;
        $scholarsAfter = $playerAfter->resources->scholars;
        $developed = $playerAfter->shippingLevel > $playerBefore->shippingLevel
            || $playerAfter->terraformingLevel > $playerBefore->terraformingLevel;

        if ($scholarsBefore === $scholarsAfter && ! $developed) {
            return 0;
        }

        $probe = $before->deepCopy();
        $probe->round->hasTakenMainAction = false;
        $player = collect($probe->players)->firstWhere('playerId', $playerBefore->playerId);
        if (! $player instanceof GamePlayerStateData) {
            return 0;
        }
        $player->resources->scholars = max(1, $scholarsBefore);
        $potential = 0;
        foreach ($this->developmentAdvancementOptionFinder->execute($probe, $player) as $option) {
            $development = $probe->deepCopy();
            $developmentPlayer = collect($development->players)->firstWhere('playerId', $player->playerId);
            if (! $developmentPlayer instanceof GamePlayerStateData) {
                continue;
            }
            if ($option->action === GameActionType::AdvanceShipping) {
                $developmentPlayer->shippingLevel = $option->targetLevel;
            } else {
                $developmentPlayer->terraformingLevel = $option->targetLevel;
            }
            $potential = max($potential, $this->boardPositionProgressEvaluator->execute(
                $probe,
                $development,
                $player->playerId,
            ));
        }
        $bonus = min(self::MAX_SCHOLAR_BONUS, $potential * self::USEFUL_DEVELOPMENT_WEIGHT);

        if ($developed) {
            $progress = max(0, $this->boardPositionProgressEvaluator->execute($before, $after, $playerBefore->playerId));

            return min(self::MAX_SCHOLAR_BONUS, $progress * self::USEFUL_DEVELOPMENT_WEIGHT);
        }

        return ((int) ($scholarsAfter > 0) - (int) ($scholarsBefore > 0)) * $bonus;
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
