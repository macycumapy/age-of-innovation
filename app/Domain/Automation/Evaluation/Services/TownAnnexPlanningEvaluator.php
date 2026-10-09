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

    /**
     * @param list<list<BoardHexStateData>> $components
     * @param list<int> $componentScores
     */
    public function execute(GameStateData $state, GamePlayerStateData $player, array $components, array $componentScores): TownAnnexPlanningData
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
        $incomeRounds = $state->round->phase === GamePhase::Setup ? 3 : min(3, max(0, 6 - $state->round->number));
        $toolBudget = $player->resources->tools + $income->tools * $incomeRounds;
        $coinBudget = $player->resources->coins + $income->coins * $incomeRounds;
        $availableGuilds = $this->availableBuildings($state, $player, BuildingType::Guild);
        $availablePalaces = $this->availableBuildings($state, $player, BuildingType::Palace);
        $availableUniversities = $this->availableBuildings($state, $player, BuildingType::University);
        $availableWorkshops = $this->availableBuildings($state, $player, BuildingType::Workshop);
        $allocations = [new TownAnnexDevelopmentPlanData()];
        $usefulAnnexHexIds = [];

        foreach ($components as $index => $component) {
            $plans = $this->componentPlans($state, $player, $component, $componentScores[$index], $toolBudget, $coinBudget);
            foreach ($plans as $plan) {
                if ($plan->requiredAnnexes <= $player->availableAnnexes
                    && $plan->guilds <= $availableGuilds && $plan->palaces <= $availablePalaces
                    && $plan->universities <= $availableUniversities) {
                    $usefulAnnexHexIds = [...$usefulAnnexHexIds, ...$plan->usefulAnnexHexIds];
                }
            }
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
                        coins: $allocation->coins + $plan->coins,
                        guilds: $allocation->guilds + $plan->guilds,
                        palaces: $allocation->palaces + $plan->palaces,
                        universities: $allocation->universities + $plan->universities,
                        workshops: $allocation->workshops + $plan->workshops,
                        score: $allocation->score + $plan->score,
                        towns: $allocation->towns + 1,
                    );
                    if ($combined->requiredAnnexes > $player->availableAnnexes
                        || $combined->tools > $toolBudget || $combined->coins > $coinBudget
                        || $combined->guilds > $availableGuilds || $combined->palaces > $availablePalaces
                        || $combined->universities > $availableUniversities || $combined->workshops > $availableWorkshops
                        || $combined->towns > $availableTowns) {
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
        foreach ($allocations as $allocation) {
            if ($allocation->score > $best->score) {
                $best = $allocation;
            }
        }

        return new TownAnnexPlanningData(
            score: $best->score + count($townIds) * self::TOWN_OPPORTUNITY_WEIGHT,
            projectedTowns: $best->towns + count($townIds),
            usefulAnnexHexIds: array_values(array_unique($usefulAnnexHexIds)),
        );
    }

    /**
     * @param list<BoardHexStateData> $component
     * @return list<TownAnnexDevelopmentPlanData>
     */
    private function componentPlans(GameStateData $state, GamePlayerStateData $player, array $component, int $currentScore, int $toolBudget, int $coinBudget): array
    {
        $plans = $this->developmentPlans($state, $player, $component, $currentScore, $toolBudget, $coinBudget);
        if (count($component) !== 2 || ($player->availableAnnexes < 1
                && ! array_any($component, static fn (BoardHexStateData $hex): bool => $hex->building->hasAnnex))
            || $this->availableBuildings($state, $player, BuildingType::Workshop) < 1) {
            return $plans;
        }
        $expansion = $this->expansionHex($state, $player, $component);
        if ($expansion === null) {
            return $plans;
        }
        $toolCost = 1 + $expansion->terrain->spadesTo($player->homeland) * max(1, 3 - $player->terraformingLevel);
        $futureWorkshop = clone $expansion;
        $futureWorkshop->building = new BuildingStateData(BuildingType::Workshop, $player->playerId);

        return [...$plans, ...$this->developmentPlans(
            $state,
            $player,
            [...$component, $futureWorkshop],
            $currentScore,
            $toolBudget,
            $coinBudget,
            new TownAnnexDevelopmentPlanData(tools: $toolCost, coins: 2, workshops: 1),
        )];
    }

    /**
     * @param list<BoardHexStateData> $component
     * @return list<TownAnnexDevelopmentPlanData>
     */
    private function developmentPlans(GameStateData $state, GamePlayerStateData $player, array $component, int $currentScore, int $toolBudget, int $coinBudget, ?TownAnnexDevelopmentPlanData $initialPlan = null): array
    {
        $variants = [$initialPlan ?? new TownAnnexDevelopmentPlanData()];
        $placedAnnexIds = [];
        foreach ($component as $hex) {
            $building = $hex->building;
            if ($building === null) {
                continue;
            }
            if ($building->hasAnnex) {
                $placedAnnexIds[] = $hex->id;
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
            if ($additionalAnnexes > count($component) - count($placedAnnexIds)
                || $additionalAnnexes > $player->availableAnnexes || $variant->tools > $toolBudget || $variant->coins > $coinBudget) {
                continue;
            }
            $completedPower = min(7, $variant->power + max($neededAnnexes, count($placedAnnexIds)));
            $score = max(0, $completedPower ** 2 * self::TOWN_COHESION_WEIGHT + self::TOWN_OPPORTUNITY_WEIGHT
                - $currentScore - ($variant->upgrades + $variant->workshops) * self::UPGRADE_UNCERTAINTY_COST);
            $plans[] = new TownAnnexDevelopmentPlanData(
                requiredAnnexes: $additionalAnnexes,
                tools: $variant->tools,
                coins: $variant->coins,
                upgrades: $variant->upgrades,
                guilds: $variant->guilds,
                palaces: $variant->palaces,
                universities: $variant->universities,
                workshops: $variant->workshops,
                score: $score,
                usefulAnnexHexIds: array_slice($placedAnnexIds, 0, $neededAnnexes),
            );
        }

        return $plans;
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
            coins: $variant->coins + $coins,
            upgrades: $variant->upgrades + (int) $isUpgrade,
            guilds: $variant->guilds + (int) ($isUpgrade && $type === BuildingType::Guild),
            palaces: $variant->palaces + (int) ($isUpgrade && $type === BuildingType::Palace),
            universities: $variant->universities + (int) ($isUpgrade && $type === BuildingType::University),
            workshops: $variant->workshops,
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
        return $plan->requiredAnnexes.':'.$plan->tools.':'.$plan->coins.':'.$plan->guilds.':'.$plan->palaces.':'.$plan->universities.':'.$plan->workshops.':'.$plan->towns;
    }

    /** @param list<BoardHexStateData> $component */
    private function expansionHex(GameStateData $state, GamePlayerStateData $player, array $component): ?BoardHexStateData
    {
        $componentIds = array_fill_keys(array_map(static fn (BoardHexStateData $hex): string => $hex->id, $component), true);
        $neighborIds = [];
        foreach ($component as $hex) {
            foreach ($hex->adjacentHexIds as $neighborId) {
                $neighborIds[$neighborId] = true;
            }
        }
        $best = null;
        foreach ($state->board->hexes as $hex) {
            if (! isset($neighborIds[$hex->id]) || $hex->building !== null || $hex->townId !== null || ! $hex->terrain->isHomeland()) {
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
            if ($best === null || $hex->terrain->spadesTo($player->homeland) < $best->terrain->spadesTo($player->homeland)) {
                $best = $hex;
            }
        }

        return $best;
    }

    private function availableBuildings(GameStateData $state, GamePlayerStateData $player, BuildingType $type): int
    {
        $placed = count(array_filter($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $player->playerId && $hex->building->type === $type && ! $hex->building->isNeutral));

        return max(0, $type->supplyLimit() - $placed);
    }
}
