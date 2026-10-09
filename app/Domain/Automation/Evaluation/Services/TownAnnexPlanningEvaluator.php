<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\Automation\Evaluation\Data\TownAnnexDevelopmentPlanData;
use App\Domain\Automation\Evaluation\Data\TownAnnexPlanningData;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Services\BuildingAdjacencyChecker;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class TownAnnexPlanningEvaluator
{
    private const int TOWN_OPPORTUNITY_WEIGHT = 160;

    private const int TOWN_COHESION_WEIGHT = 8;

    private const int UPGRADE_UNCERTAINTY_COST = 40;

    private const int MAX_FORECAST_UPGRADES = 3;

    private const int MAX_FORECAST_WORKSHOPS = 3;

    private const int MAX_EXPANSION_BRANCHES = 2;

    private const int MAX_EXPANSION_PLANS = 4;

    private const int REMAINING_TERRAFORMING_COST_WEIGHT = 12;

    private const int MISSING_TOOL_COST = 80;

    private const int MISSING_COIN_COST = 40;

    /**
     * @param list<list<BoardHexStateData>> $components
     * @param list<int> $componentScores
     */
    public function execute(GameStateData $state, GamePlayerStateData $player, array $components, array $componentScores, bool $includeFutureIncome = true, bool $compareDevelopmentEconomy = false, bool $valueResourceShortfall = false): TownAnnexPlanningData
    {
        $townIds = [];
        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId === $player->playerId && $hex->townId !== null) {
                $townIds[$hex->townId] = true;
            }
        }
        $availableTowns = count($state->availableTownTileIds);
        if ($availableTowns === 0) {
            return new TownAnnexPlanningData(count($townIds) * self::TOWN_OPPORTUNITY_WEIGHT, count($townIds), []);
        }
        $income = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $incomeRounds = ! $includeFutureIncome ? 0 : ($state->round->phase === GamePhase::Setup ? 3 : min(3, max(0, 6 - $state->round->number)));
        $toolBudget = $player->resources->tools + $income->tools * $incomeRounds;
        $coinBudget = $player->resources->coins + $income->coins * $incomeRounds;
        $forecastToolBudget = $toolBudget + ($valueResourceShortfall ? 7 : 0);
        $forecastCoinBudget = $coinBudget + ($valueResourceShortfall ? 14 : 0);
        $availableGuilds = $this->availableBuildings($state, $player, BuildingType::Guild);
        $availablePalaces = $this->availableBuildings($state, $player, BuildingType::Palace);
        $availableUniversities = $this->availableBuildings($state, $player, BuildingType::University);
        $availableWorkshops = $this->availableBuildings($state, $player, BuildingType::Workshop);
        $allocations = [new TownAnnexDevelopmentPlanData()];

        foreach ($components as $index => $component) {
            $plans = $this->componentPlans($state, $player, $component, $componentScores[$index], $forecastToolBudget, $forecastCoinBudget, $compareDevelopmentEconomy);
            $nextAllocations = [];
            foreach ($allocations as $allocation) {
                $allocationKey = $this->allocationKey($allocation);
                if (! isset($nextAllocations[$allocationKey]) || $nextAllocations[$allocationKey]->score < $allocation->score) {
                    $nextAllocations[$allocationKey] = $allocation;
                }
                foreach ($plans as $plan) {
                    $combined = new TownAnnexDevelopmentPlanData(
                        requiredAnnexes: $allocation->requiredAnnexes + $plan->requiredAnnexes,
                        tools: $allocation->tools + $plan->tools,
                        terraformingTools: $allocation->terraformingTools + $plan->terraformingTools,
                        coins: $allocation->coins + $plan->coins,
                        upgrades: $allocation->upgrades + $plan->upgrades,
                        guilds: $allocation->guilds + $plan->guilds,
                        palaces: $allocation->palaces + $plan->palaces,
                        universities: $allocation->universities + $plan->universities,
                        workshops: $allocation->workshops + $plan->workshops,
                        score: $allocation->score + $plan->score,
                        towns: $allocation->towns + 1,
                        usefulAnnexHexIds: [...$allocation->usefulAnnexHexIds, ...$plan->usefulAnnexHexIds],
                        expansionHexIds: [...$allocation->expansionHexIds, ...$plan->expansionHexIds],
                        annexCandidateHexIds: [...$allocation->annexCandidateHexIds, ...$plan->annexCandidateHexIds],
                    );
                    if ($combined->requiredAnnexes > $player->availableAnnexes
                        || $combined->tools > $forecastToolBudget || $combined->coins > $forecastCoinBudget
                        || $combined->guilds > $availableGuilds || $combined->palaces > $availablePalaces
                        || $combined->universities > $availableUniversities || $combined->workshops > $availableWorkshops
                        || $combined->towns > $availableTowns
                        || $this->expansionsConflict($state, $player->playerId, $allocation->expansionHexIds, $plan->expansionHexIds)) {
                        continue;
                    }
                    $key = $this->allocationKey($combined);
                    if (! isset($nextAllocations[$key]) || $nextAllocations[$key]->score < $combined->score) {
                        $nextAllocations[$key] = $combined;
                    }
                }
            }
            $allocations = array_values($nextAllocations);
        }

        $best = new TownAnnexDevelopmentPlanData();
        $bestScore = 0;
        foreach ($allocations as $allocation) {
            $score = $allocation->score - max(0, $allocation->tools - $toolBudget) * self::MISSING_TOOL_COST
                - max(0, $allocation->coins - $coinBudget) * self::MISSING_COIN_COST;
            if ($score > $bestScore) {
                $best = $allocation;
                $bestScore = $score;
            }
        }

        return new TownAnnexPlanningData(
            score: $bestScore + count($townIds) * self::TOWN_OPPORTUNITY_WEIGHT,
            projectedTowns: $best->towns + count($townIds),
            usefulAnnexHexIds: array_values(array_unique($best->usefulAnnexHexIds)),
            reservedTools: $best->tools,
            reservedCoins: $best->coins,
            expansionHexIds: $best->expansionHexIds,
            requiredAnnexes: $best->requiredAnnexes,
            remainingActions: $best->upgrades + $best->workshops + $best->requiredAnnexes,
            annexCandidateHexIds: array_values(array_unique($best->annexCandidateHexIds)),
        );
    }

    /**
     * @param list<BoardHexStateData> $component
     * @return list<TownAnnexDevelopmentPlanData>
     */
    private function componentPlans(GameStateData $state, GamePlayerStateData $player, array $component, int $currentScore, int $toolBudget, int $coinBudget, bool $compareDevelopmentEconomy): array
    {
        $plans = $this->developmentPlans($state, $player, $component, $currentScore, $toolBudget, $coinBudget, compareDevelopmentEconomy: $compareDevelopmentEconomy);
        $maxWorkshops = min(self::MAX_FORECAST_WORKSHOPS, $this->availableBuildings($state, $player, BuildingType::Workshop));
        $frontier = [new TownAnnexDevelopmentPlanData()];
        for ($workshops = 1; $workshops <= $maxWorkshops; $workshops++) {
            $next = [];
            foreach ($frontier as $partial) {
                $expandedComponent = $component;
                foreach ($state->board->hexes as $hex) {
                    if (in_array($hex->id, $partial->expansionHexIds, true)) {
                        $future = clone $hex;
                        $future->building = new BuildingStateData(BuildingType::Workshop, $player->playerId);
                        $expandedComponent[] = $future;
                    }
                }
                foreach (array_slice($this->expansionHexes($state, $player, $expandedComponent), 0, self::MAX_EXPANSION_BRANCHES) as $expansion) {
                    $terraformingTools = $partial->terraformingTools + $expansion->terrain->spadesTo($player->homeland) * max(1, 3 - $player->terraformingLevel);
                    $tools = $workshops + $terraformingTools;
                    if ($tools > $toolBudget || $workshops * 2 > $coinBudget) {
                        continue;
                    }
                    $initial = new TownAnnexDevelopmentPlanData(
                        tools: $tools,
                        terraformingTools: $terraformingTools,
                        coins: $workshops * 2,
                        workshops: $workshops,
                        expansionHexIds: [...$partial->expansionHexIds, $expansion->id]
                    );
                    $future = clone $expansion;
                    $future->building = new BuildingStateData(BuildingType::Workshop, $player->playerId);
                    $plans = [...$plans, ...$this->developmentPlans($state, $player, [...$expandedComponent, $future], $currentScore, $toolBudget, $coinBudget, $initial, $compareDevelopmentEconomy)];
                    $next[] = $initial;
                }
            }
            if ($next === []) {
                break;
            }
            usort($next, static fn (TownAnnexDevelopmentPlanData $first, TownAnnexDevelopmentPlanData $second): int => $first->tools <=> $second->tools);
            $frontier = array_slice($next, 0, self::MAX_EXPANSION_PLANS);
        }

        return $plans;
    }

    /**
     * @param list<BoardHexStateData> $component
     * @return list<TownAnnexDevelopmentPlanData>
     */
    private function developmentPlans(GameStateData $state, GamePlayerStateData $player, array $component, int $currentScore, int $toolBudget, int $coinBudget, ?TownAnnexDevelopmentPlanData $initialPlan = null, bool $compareDevelopmentEconomy = false): array
    {
        $variants = [$initialPlan ?? new TownAnnexDevelopmentPlanData()];
        $placedAnnexIds = [];
        $annexCandidates = [];
        foreach ($component as $hex) {
            $building = $hex->building;
            if ($building === null) {
                continue;
            }
            if ($building->hasAnnex) {
                $placedAnnexIds[] = $hex->id;
            } elseif (! $building->isNeutral && $building->ownerPlayerId === $player->playerId) {
                $annexCandidates[] = $hex->id;
            }
            $nextVariants = [];
            foreach ($variants as $variant) {
                $nextVariants[] = $this->extendVariant($variant, $building->type);
                if ($building->isNeutral || $variant->upgrades >= self::MAX_FORECAST_UPGRADES) {
                    continue;
                }
                foreach ($building->type->upgradeOptions() as $target) {
                    if ($target->powerValue() <= $building->type->powerValue()) {
                        continue;
                    }
                    $cost = $building->type->upgradeCostTo($target, BuildingAdjacencyChecker::hasOpponent($state->board, $hex, $player->playerId));
                    if ($variant->tools + $cost['tools'] > $toolBudget || $variant->coins + $cost['coins'] > $coinBudget
                        || $this->availableBuildings($state, $player, $target) < 1) {
                        continue;
                    }
                    $nextVariants[] = $this->extendVariant($variant, $target, $cost['tools'], $cost['coins']);
                }
            }
            $variants = $this->uniqueVariants($nextVariants);
        }

        $requiredPower = $player->palaceId === PalaceAbility::Palace08->value ? 6 : 7;
        $plans = [];
        foreach ($variants as $variant) {
            $neededAnnexes = max(0, $requiredPower - $variant->power, $variant->minimumBuildings - count($component));
            $additionalAnnexes = max(0, $neededAnnexes - count($placedAnnexIds));
            if ($additionalAnnexes > count($annexCandidates)
                || $additionalAnnexes > $player->availableAnnexes || $variant->tools > $toolBudget || $variant->coins > $coinBudget) {
                continue;
            }
            $completedPower = min(7, $variant->power + max($neededAnnexes, count($placedAnnexIds)));
            $score = max(0, $completedPower ** 2 * self::TOWN_COHESION_WEIGHT + self::TOWN_OPPORTUNITY_WEIGHT
                - $currentScore - ($variant->upgrades + $variant->workshops) * self::UPGRADE_UNCERTAINTY_COST
                - $variant->terraformingTools * self::REMAINING_TERRAFORMING_COST_WEIGHT
                + ($compareDevelopmentEconomy ? $this->developmentEconomy($state, $player, $variant) : 0));
            $plans[] = new TownAnnexDevelopmentPlanData(
                requiredAnnexes: $additionalAnnexes,
                tools: $variant->tools,
                terraformingTools: $variant->terraformingTools,
                coins: $variant->coins,
                upgrades: $variant->upgrades,
                guilds: $variant->guilds,
                palaces: $variant->palaces,
                universities: $variant->universities,
                workshops: $variant->workshops,
                score: $score,
                usefulAnnexHexIds: array_slice($placedAnnexIds, 0, $neededAnnexes),
                expansionHexIds: $variant->expansionHexIds,
                annexCandidateHexIds: $additionalAnnexes > 0 ? $annexCandidates : [],
            );
        }

        return $plans;
    }

    private function developmentEconomy(GameStateData $state, GamePlayerStateData $player, TownAnnexDevelopmentPlanData $plan): int
    {
        $workshops = count(array_filter($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $player->playerId && ! $hex->building->isNeutral
            && $hex->building->type === BuildingType::Workshop));
        $futureWorkshops = $workshops + $plan->workshops - $plan->guilds;
        $toolIncomeChange = $futureWorkshops - (int) ($futureWorkshops >= 5)
            - $workshops + (int) ($workshops >= 5);
        $incomeRounds = min(2, max(0, 6 - $state->round->number));

        return $toolIncomeChange * $incomeRounds * 20 + $plan->workshops * 12
            - $plan->tools * 4 - $plan->coins * 2;
    }

    private function extendVariant(TownAnnexDevelopmentPlanData $variant, BuildingType $type, int $tools = 0, int $coins = 0): TownAnnexDevelopmentPlanData
    {
        $isUpgrade = $tools > 0 || $coins > 0;

        return new TownAnnexDevelopmentPlanData(
            power: $variant->power + $type->powerValue(),
            minimumBuildings: min($variant->minimumBuildings, match ($type) {
                BuildingType::University => 3,
                BuildingType::Monument => 2,
                default => 4,
            }),
            tools: $variant->tools + $tools,
            terraformingTools: $variant->terraformingTools,
            coins: $variant->coins + $coins,
            upgrades: $variant->upgrades + (int) $isUpgrade,
            guilds: $variant->guilds + (int) ($isUpgrade && $type === BuildingType::Guild),
            palaces: $variant->palaces + (int) ($isUpgrade && $type === BuildingType::Palace),
            universities: $variant->universities + (int) ($isUpgrade && $type === BuildingType::University),
            workshops: $variant->workshops,
            expansionHexIds: $variant->expansionHexIds,
        );
    }

    /**
     * @param list<TownAnnexDevelopmentPlanData> $variants
     * @return list<TownAnnexDevelopmentPlanData>
     */
    private function uniqueVariants(array $variants): array
    {
        $unique = [];
        foreach ($variants as $variant) {
            $key = $variant->power.':'.$variant->minimumBuildings.':'.$variant->upgrades.':'.$this->allocationKey($variant);
            $unique[$key] = $variant;
        }

        return array_values($unique);
    }

    private function allocationKey(TownAnnexDevelopmentPlanData $plan): string
    {
        $expansionHexIds = $plan->expansionHexIds;
        sort($expansionHexIds, SORT_STRING);

        return $plan->requiredAnnexes.':'.$plan->tools.':'.$plan->terraformingTools.':'.$plan->coins.':'.$plan->guilds.':'.$plan->palaces.':'.$plan->universities.':'.$plan->workshops.':'.$plan->towns.':'.implode(',', $expansionHexIds);
    }

    /**
     * @param list<string> $first
     * @param list<string> $second
     */
    private function expansionsConflict(GameStateData $state, int $playerId, array $first, array $second): bool
    {
        if ($first === [] || $second === []) {
            return false;
        }
        if (array_intersect($first, $second) !== []) {
            return true;
        }

        foreach ($state->board->hexes as $hex) {
            if (in_array($hex->id, $first, true) && array_intersect($hex->adjacentHexIds, $second) !== []) {
                return true;
            }
        }
        foreach ($state->board->bridges as $bridge) {
            if ($bridge->ownerPlayerId === $playerId
                && ((in_array($bridge->fromHexId, $first, true) && in_array($bridge->toHexId, $second, true))
                    || (in_array($bridge->toHexId, $first, true) && in_array($bridge->fromHexId, $second, true)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<BoardHexStateData> $component
     * @return list<BoardHexStateData>
     */
    private function expansionHexes(GameStateData $state, GamePlayerStateData $player, array $component): array
    {
        $componentIds = array_fill_keys(array_map(static fn (BoardHexStateData $hex): string => $hex->id, $component), true);
        $neighborIds = [];
        foreach ($component as $hex) {
            foreach ($hex->adjacentHexIds as $neighborId) {
                $neighborIds[$neighborId] = true;
            }
        }
        foreach ($state->board->bridges as $bridge) {
            if ($bridge->ownerPlayerId !== $player->playerId) {
                continue;
            }
            if (isset($componentIds[$bridge->fromHexId])) {
                $neighborIds[$bridge->toHexId] = true;
            }
            if (isset($componentIds[$bridge->toHexId])) {
                $neighborIds[$bridge->fromHexId] = true;
            }
        }
        $candidates = [];
        foreach ($state->board->hexes as $hex) {
            if (isset($componentIds[$hex->id]) || ! isset($neighborIds[$hex->id]) || $hex->building !== null || $hex->townId !== null || ! $hex->terrain->isHomeland()) {
                continue;
            }
            $connections = $hex->adjacentHexIds;
            foreach ($state->board->bridges as $bridge) {
                if ($bridge->ownerPlayerId !== $player->playerId) {
                    continue;
                }
                if ($bridge->fromHexId === $hex->id) {
                    $connections[] = $bridge->toHexId;
                } elseif ($bridge->toHexId === $hex->id) {
                    $connections[] = $bridge->fromHexId;
                }
            }
            if (array_any($state->board->hexes, static fn (BoardHexStateData $neighbor): bool =>
                $neighbor->building?->ownerPlayerId === $player->playerId
                && in_array($neighbor->id, $connections, true) && ! isset($componentIds[$neighbor->id]))) {
                continue;
            }
            $candidates[] = $hex;
        }
        usort($candidates, static fn (BoardHexStateData $first, BoardHexStateData $second): int => $first->terrain->spadesTo($player->homeland) <=> $second->terrain->spadesTo($player->homeland));

        return $candidates;
    }

    private function availableBuildings(GameStateData $state, GamePlayerStateData $player, BuildingType $type): int
    {
        $placed = count(array_filter($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $player->playerId && $hex->building->type === $type && ! $hex->building->isNeutral));

        return max(0, $type->supplyLimit() - $placed);
    }
}
