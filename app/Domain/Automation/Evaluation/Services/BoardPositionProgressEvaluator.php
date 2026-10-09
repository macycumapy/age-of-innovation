<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\Automation\Data\StrategicPlanData;
use App\Domain\Automation\Enums\StrategicApproach;
use App\Domain\Automation\Enums\StrategicObjective;
use App\Domain\Automation\Services\NetworkConnectionPlanner;
use App\Domain\GameEngine\Board\Actions\FindReachableLandHexesAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Board\Services\LargestNetworkSizeCalculator;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringGoal;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

class BoardPositionProgressEvaluator
{
    private const int REACHABLE_HOMELAND_WEIGHT = 3;

    private const int TOWN_COHESION_WEIGHT = 8;

    private const int USEFUL_TOWN_ANNEX_WEIGHT = 25;

    private const int STANDARD_TOWN_POWER = 7;

    private const int NETWORK_COHESION_WEIGHT = 10;

    private const int OPEN_DIRECTION_WEIGHT = 2;

    private const int CONTESTED_POSITION_WEIGHT = 5;

    private const int NEARBY_OPPONENT_WEIGHT = 6;

    private const int STRANDED_TERRAFORMING_PENALTY = 40;

    private const int MAX_FORECAST_EXPANSION_TARGETS = 3;

    private const int COMPLETED_TOWN_EXPANSION_PENALTY = 30;

    private const int EARLY_COMPLETED_TOWN_DEVELOPMENT_PENALTY = 120;

    private const int REDUNDANT_BRIDGE_PENALTY = 15;

    private const int STARTING_CONNECTION_WEIGHT = 6;

    private const int MERGED_STARTING_HOUSE_PENALTY = 80;

    private const int OVERLAPPING_STARTING_EXPANSION_PENALTY = 80;

    private const int UNFUNDED_BRIDGE_PENALTY = 60;

    private const int ISOLATED_BUILDING_WEIGHT = 12;

    private const int STRANDED_PALACE_GUILD_PENALTY = 80;

    private const int FORECAST_WORKSHOP_COST = 3;

    private const int FORECAST_RIVER_CONNECTION_COST = 4;

    private const int AVOIDABLE_ANNEX_COST = 80;

    private const int PREMATURE_ANNEX_COST = 120;

    private const int EARLY_AVOIDABLE_ANNEX_COST = 200;

    private const int COMPLETED_TOWN_ANNEX_PENALTY = 40;

    private const int PARTIAL_TOWN_TERRAFORMING_WEIGHT = 12;

    public function __construct(
        private FindReachableLandHexesAction $findReachableLandHexes,
        private NetworkBlockingRiskEvaluator $networkBlockingRiskEvaluator,
        private TownAnnexPlanningEvaluator $townAnnexPlanningEvaluator,
        private StartingExpansionSafetyEvaluator $startingExpansionSafetyEvaluator,
        private OwnedBuildingComponentFinder $ownedBuildingComponentFinder,
        private DevelopmentInvestmentEvaluator $developmentInvestmentEvaluator,
        private NetworkConnectionPlanner $networkConnectionPlanner,
    ) {
    }

    public function execute(GameStateData $before, GameStateData $after, int $playerId, ?StrategicPlanData $strategicPlan = null): int
    {
        $score = $this->positionScore($after, $playerId)
            - $this->positionScore($before, $playerId)
            - $this->strandedTerraformingPenalty($before, $after, $playerId)
            - $this->unproductiveNetworkDevelopmentPenalty($before, $after, $playerId, $strategicPlan)
            - $this->avoidableAnnexPenalty($before, $after, $playerId)
            - $this->strandedPalaceGuildPenalty($before, $after, $playerId)
            + $this->partialTownTerraformingProgress($before, $after, $playerId)
            + $this->networkBlockingRiskEvaluator->execute($before, $after, $playerId);
        $previous = collect($before->players)->firstWhere('playerId', $playerId);
        $player = collect($after->players)->firstWhere('playerId', $playerId);
        if ($score > 0 && $previous instanceof GamePlayerStateData && $player instanceof GamePlayerStateData
            && $player->shippingLevel > $previous->shippingLevel
            && LargestNetworkSizeCalculator::calculate($player, $after->board)
                <= LargestNetworkSizeCalculator::calculate($previous, $before->board)) {
            return min($score, $this->developmentInvestmentEvaluator->execute($before, $after, $playerId));
        }

        return $score;
    }

    private function strandedPalaceGuildPenalty(GameStateData $before, GameStateData $after, int $playerId): int
    {
        if ($before->pendingInteraction?->type !== PendingInteractionType::PlacePalaceGuild
            || $before->pendingInteraction->playerId !== $playerId) {
            return 0;
        }
        $previous = collect($before->board->hexes)->keyBy('id');
        $target = collect($after->board->hexes)->first(static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $playerId && $hex->building->type === BuildingType::Guild
            && $previous->get($hex->id)?->building === null);
        $player = collect($after->players)->firstWhere('playerId', $playerId);
        if (! $target instanceof BoardHexStateData || ! $player instanceof GamePlayerStateData) {
            return 0;
        }
        $components = $this->buildingComponents($after, $playerId);
        foreach ($components as $index => $component) {
            if (! array_any($component, static fn (BoardHexStateData $hex): bool => $hex->id === $target->id)) {
                continue;
            }
            if (count($component) > 1
                || LargestNetworkSizeCalculator::calculate($player, $after->board)
                    > LargestNetworkSizeCalculator::calculate($player, $before->board)) {
                return 0;
            }
            foreach ($components as $otherIndex => $other) {
                if ($index !== $otherIndex && $this->networkConnectionPlanner->execute($after, $player, [$component, $other]) !== null) {
                    return 0;
                }
                if ($index !== $otherIndex && $this->canConnectByShipping($after, $player, $component, $other)) {
                    return 0;
                }
            }

            return intdiv(self::STRANDED_PALACE_GUILD_PENALTY * min(2, max(0, 6 - $after->round->number)), 2);
        }

        return 0;
    }

    /**
     * @param list<BoardHexStateData> $source
     * @param list<BoardHexStateData> $destination
     */
    private function canConnectByShipping(GameStateData $state, GamePlayerStateData $player, array $source, array $destination): bool
    {
        $board = clone $state->board;
        $board->hexes = array_map(static function (BoardHexStateData $hex) use ($source, $destination): BoardHexStateData {
            $copy = clone $hex;
            if (! in_array($hex, $source, true) && ! in_array($hex, $destination, true)) {
                $copy->building = null;
            }

            return $copy;
        }, $board->hexes);
        $income = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $incomeRounds = min(2, max(0, 6 - $state->round->number));
        $shippingPlayer = clone $player;
        for ($level = $player->shippingLevel; $level <= 3; $level++) {
            $steps = $level - $player->shippingLevel;
            if ($steps * 4 > $player->resources->coins + $income->coins * $incomeRounds
                || $steps > $player->resources->scholars + min($player->scholarPoolSize, $income->scholars * $incomeRounds)) {
                continue;
            }
            $shippingPlayer->shippingLevel = $level;
            if (LargestNetworkSizeCalculator::calculate($shippingPlayer, $board) > max(count($source), count($destination))) {
                return true;
            }
        }

        return false;
    }

    private function partialTownTerraformingProgress(GameStateData $before, GameStateData $after, int $playerId): int
    {
        $player = collect($after->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData || $after->round->phase !== GamePhase::Actions) {
            return 0;
        }
        $previous = collect($before->board->hexes)->keyBy('id');
        $partialHexes = array_filter($after->board->hexes, static function (BoardHexStateData $hex) use ($previous, $player): bool {
            $original = $previous->get($hex->id);

            return $original instanceof BoardHexStateData && $hex->building === null
                && $hex->terrain->isHomeland() && $original->terrain->isHomeland()
                && $hex->terrain !== $player->homeland
                && $hex->terrain->spadesTo($player->homeland) < $original->terrain->spadesTo($player->homeland);
        });
        if ($partialHexes === []) {
            return 0;
        }
        $hasWorkshop = count(array_filter($after->board->hexes, static fn (BoardHexStateData $owned): bool =>
            $owned->building?->ownerPlayerId === $playerId && ! $owned->building->isNeutral
            && $owned->building->type === BuildingType::Workshop)) < BuildingType::Workshop->supplyLimit();
        $unfinishedNeighbors = [];
        $completedNeighbors = [];
        foreach ($this->buildingComponents($after, $playerId) as $component) {
            $completed = array_any($component, static fn (BoardHexStateData $hex): bool => $hex->townId !== null);
            foreach ($component as $hex) {
                $neighbors = $hex->adjacentHexIds;
                foreach ($after->board->bridges as $bridge) {
                    if ($bridge->ownerPlayerId !== $playerId) {
                        continue;
                    }
                    if ($bridge->fromHexId === $hex->id) {
                        $neighbors[] = $bridge->toHexId;
                    } elseif ($bridge->toHexId === $hex->id) {
                        $neighbors[] = $bridge->fromHexId;
                    }
                }
                foreach ($neighbors as $neighborId) {
                    if ($completed) {
                        $completedNeighbors[$neighborId] = true;
                    } else {
                        $unfinishedNeighbors[$neighborId] = true;
                    }
                }
            }
        }
        $score = 0;
        foreach ($partialHexes as $hex) {
            $remainingTools = 1 + max(0, $hex->terrain->spadesTo($player->homeland) - $player->unassignedSpades)
                * max(1, 3 - $player->terraformingLevel);
            if (! $hasWorkshop || $player->resources->tools < $remainingTools || $player->resources->coins < 2
                || isset($completedNeighbors[$hex->id])) {
                $score -= self::PARTIAL_TOWN_TERRAFORMING_WEIGHT;
            } elseif (isset($unfinishedNeighbors[$hex->id])) {
                $score += self::PARTIAL_TOWN_TERRAFORMING_WEIGHT;
            }
        }

        return $score;
    }

    private function avoidableAnnexPenalty(GameStateData $before, GameStateData $after, int $playerId): int
    {
        $player = collect($before->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            return 0;
        }
        $previous = collect($before->board->hexes)->keyBy('id');
        $added = [];
        foreach ($after->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId === $playerId && $hex->building->hasAnnex
                && $previous->get($hex->id)?->building?->hasAnnex === false) {
                $added[$hex->id] = true;
            }
        }
        if ($added === []) {
            return 0;
        }
        $withoutAnnexes = clone $player;
        $withoutAnnexes->availableAnnexes = 0;
        $penalty = 0;
        foreach ($this->buildingComponents($before, $playerId) as $component) {
            if (! array_any($component, static fn (BoardHexStateData $hex): bool => isset($added[$hex->id]))
                || array_any($component, static fn (BoardHexStateData $hex): bool => $hex->townId !== null)) {
                continue;
            }
            $earlyAnnex = $before->round->phase === GamePhase::Actions && $before->round->number < 5
                && RoundScoringTile::tryFrom((string) $before->round->scoringTileId)?->goal() !== RoundScoringGoal::Town;
            if ($earlyAnnex) {
                $penalty += self::PREMATURE_ANNEX_COST;
            }
            $plan = $this->townAnnexPlanningEvaluator->execute($before, $withoutAnnexes, [$component], [0], includeFutureIncome: $earlyAnnex);
            if ($plan->expansionHexIds !== [] || ($earlyAnnex && $this->hasAffordableAdjacentWorkshop($before, $player, $component))) {
                $penalty += $earlyAnnex ? self::EARLY_AVOIDABLE_ANNEX_COST : self::AVOIDABLE_ANNEX_COST;
            }
        }

        return $penalty;
    }

    /** @param list<BoardHexStateData> $component */
    private function hasAffordableAdjacentWorkshop(GameStateData $state, GamePlayerStateData $player, array $component): bool
    {
        $workshops = count(array_filter($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $player->playerId && ! $hex->building->isNeutral
            && $hex->building->type === BuildingType::Workshop));
        if ($workshops >= BuildingType::Workshop->supplyLimit()) {
            return false;
        }
        $componentIds = array_fill_keys(array_map(static fn (BoardHexStateData $hex): string => $hex->id, $component), true);
        $income = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $incomeRounds = min(2, max(0, 6 - $state->round->number));
        $tools = $player->resources->tools + $income->tools * $incomeRounds;
        $coins = $player->resources->coins + $income->coins * $incomeRounds;

        return $coins >= 2 && array_any($state->board->hexes, static function (BoardHexStateData $hex) use ($componentIds, $player, $tools): bool {
            if ($hex->building !== null || ! $hex->terrain->isHomeland()
                || ! array_any($hex->adjacentHexIds, static fn (string $id): bool => isset($componentIds[$id]))) {
                return false;
            }
            $toolCost = 1 + max(0, $hex->terrain->spadesTo($player->homeland) - $player->unassignedSpades)
                * max(1, 3 - $player->terraformingLevel);

            return $toolCost <= $tools;
        });
    }

    private function unproductiveNetworkDevelopmentPenalty(GameStateData $before, GameStateData $after, int $playerId, ?StrategicPlanData $plan): int
    {
        $beforeHexes = collect($before->board->hexes)->keyBy('id');
        $afterHexes = collect($after->board->hexes)->keyBy('id');
        $player = collect($after->players)->firstWhere('playerId', $playerId);
        $beforePlayer = collect($before->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData || ! $beforePlayer instanceof GamePlayerStateData) {
            return 0;
        }
        $components = $this->buildingComponents($before, $playerId);
        $networkRouteHexIds = [];
        if ($plan?->objective === StrategicObjective::ConnectNetwork && $plan->approach === StrategicApproach::Build) {
            $townIds = [];
            $validAnchors = $plan->anchorHexIds !== [];
            foreach ($plan->anchorHexIds as $anchor) {
                $member = $beforeHexes->get($anchor);
                if ($member?->townId === null || $member->building?->ownerPlayerId !== $playerId) {
                    $validAnchors = false;
                    break;
                }
                $townIds[$member->townId] = true;
            }
            if ($validAnchors && count($townIds) >= 2) {
                $networkRouteHexIds = array_fill_keys($plan->expansionHexIds, true);
            }
        }
        $componentByHex = [];
        foreach ($components as $index => $component) {
            foreach ($component as $hex) {
                $componentByHex[$hex->id] = $index;
            }
        }
        $penalty = 0;
        foreach ($after->board->bridges as $bridge) {
            if ($bridge->ownerPlayerId !== $playerId || array_any(
                $before->board->bridges,
                static fn (BridgeStateData $existing): bool => $existing->ownerPlayerId === $playerId
                    && (($existing->fromHexId === $bridge->fromHexId && $existing->toHexId === $bridge->toHexId)
                        || ($existing->fromHexId === $bridge->toHexId && $existing->toHexId === $bridge->fromHexId))
            )) {
                continue;
            }
            if (isset($componentByHex[$bridge->fromHexId], $componentByHex[$bridge->toHexId])
                && $componentByHex[$bridge->fromHexId] === $componentByHex[$bridge->toHexId]) {
                $penalty += self::REDUNDANT_BRIDGE_PENALTY;
                continue;
            }
            $earlyDevelopment = $before->round->phase === GamePhase::Actions && $before->round->number < 6;
            $touchesCompletedTown = array_any(
                [$bridge->fromHexId, $bridge->toHexId],
                static fn (string $hexId): bool => isset($componentByHex[$hexId])
                    && array_any($components[$componentByHex[$hexId]], static fn (BoardHexStateData $member): bool => $member->townId !== null)
            );
            $connectsCompletedTowns = isset($componentByHex[$bridge->fromHexId], $componentByHex[$bridge->toHexId])
                && array_all([$bridge->fromHexId, $bridge->toHexId], static fn (string $id): bool =>
                    array_any($components[$componentByHex[$id]], static fn (BoardHexStateData $member): bool => $member->townId !== null));
            if ($earlyDevelopment && $touchesCompletedTown && ! $connectsCompletedTowns) {
                $penalty += self::EARLY_COMPLETED_TOWN_DEVELOPMENT_PENALTY;
            }
            foreach ([$bridge->fromHexId, $bridge->toHexId] as $hexId) {
                $target = $afterHexes->get($hexId);
                if ($target === null || $target->building?->ownerPlayerId === $playerId) {
                    continue;
                }
                if (! $this->canFundBridgeTarget($after, $player, $target)) {
                    $penalty += self::UNFUNDED_BRIDGE_PENALTY;
                } else {
                    $penalty += $target->terrain->spadesTo($player->homeland)
                        * max(1, 3 - $player->terraformingLevel) * self::STARTING_CONNECTION_WEIGHT;
                }
            }
        }
        foreach ($after->board->hexes as $hex) {
            $previousHex = $beforeHexes->get($hex->id);
            if ($before->round->number < 6 && ! isset($networkRouteHexIds[$hex->id]) && $hex->building === null
                && $previousHex instanceof BoardHexStateData && $previousHex->building === null
                && $hex->terrain->isHomeland() && $previousHex->terrain->isHomeland()
                && $hex->terrain->spadesTo($player->homeland) < $previousHex->terrain->spadesTo($player->homeland)) {
                $neighbors = $hex->adjacentHexIds;
                foreach ($after->board->bridges as $bridge) {
                    if ($bridge->ownerPlayerId !== $playerId) {
                        continue;
                    }
                    if ($bridge->fromHexId === $hex->id) {
                        $neighbors[] = $bridge->toHexId;
                    } elseif ($bridge->toHexId === $hex->id) {
                        $neighbors[] = $bridge->fromHexId;
                    }
                }
                $connectedComponents = [];
                foreach ($neighbors as $neighborId) {
                    if (isset($componentByHex[$neighborId])) {
                        $connectedComponents[$componentByHex[$neighborId]] = true;
                    }
                }
                if (count($connectedComponents) === 1
                    && array_any($components[array_key_first($connectedComponents)], static fn (BoardHexStateData $member): bool => $member->townId !== null)) {
                    $penalty += self::EARLY_COMPLETED_TOWN_DEVELOPMENT_PENALTY;
                }
            }
            if ($hex->building?->ownerPlayerId !== $playerId) {
                continue;
            }
            $previousBuilding = $beforeHexes->get($hex->id)?->building;
            $earlyDevelopment = $before->round->phase === GamePhase::Actions && $before->round->number < 6;
            if ($previousBuilding !== null) {
                if ($previousBuilding->ownerPlayerId === $playerId && isset($componentByHex[$hex->id])
                    && ! $previousBuilding->hasAnnex && $hex->building->hasAnnex
                    && array_any($components[$componentByHex[$hex->id]], static fn (BoardHexStateData $member): bool => $member->townId !== null)) {
                    $penalty += $earlyDevelopment
                        ? self::EARLY_COMPLETED_TOWN_DEVELOPMENT_PENALTY
                        : self::COMPLETED_TOWN_ANNEX_PENALTY;
                }
                if ($earlyDevelopment && $previousBuilding->ownerPlayerId === $playerId
                    && ! $hex->building->isNeutral && isset($componentByHex[$hex->id])
                    && $previousBuilding->type !== $hex->building->type
                    && array_any($components[$componentByHex[$hex->id]], static fn (BoardHexStateData $member): bool => $member->townId !== null)) {
                    $penalty += self::EARLY_COMPLETED_TOWN_DEVELOPMENT_PENALTY;
                }
                continue;
            }
            $neighbors = $hex->adjacentHexIds;
            foreach ($after->board->bridges as $bridge) {
                if ($bridge->ownerPlayerId === $playerId) {
                    if ($bridge->fromHexId === $hex->id) {
                        $neighbors[] = $bridge->toHexId;
                    } elseif ($bridge->toHexId === $hex->id) {
                        $neighbors[] = $bridge->fromHexId;
                    }
                }
            }
            $connectedComponents = [];
            $joinsTown = false;
            foreach ($neighbors as $neighborId) {
                if (! isset($componentByHex[$neighborId])) {
                    continue;
                }
                $connectedComponents[$componentByHex[$neighborId]] = true;
                $joinsTown = $joinsTown || array_any(
                    $components[$componentByHex[$neighborId]],
                    static fn (BoardHexStateData $neighbor): bool => $neighbor->townId !== null
                );
            }
            if ($joinsTown && count($connectedComponents) === 1) {
                $penalty += $earlyDevelopment && ! isset($networkRouteHexIds[$hex->id])
                    ? self::EARLY_COMPLETED_TOWN_DEVELOPMENT_PENALTY
                    : self::COMPLETED_TOWN_EXPANSION_PENALTY;
            } elseif ($connectedComponents === [] && $hex->building->type === BuildingType::Workshop
                && ($player->resources->tools < $beforePlayer->resources->tools || $player->resources->coins < $beforePlayer->resources->coins)
                && $after->round->phase === GamePhase::Actions
                && $after->round->number >= 3
                && LargestNetworkSizeCalculator::calculate($player, $after->board)
                    <= LargestNetworkSizeCalculator::calculate($player, $before->board)) {
                $penalty += ($after->round->number - 2) * self::ISOLATED_BUILDING_WEIGHT;
            }
        }

        return $penalty;
    }

    private function canFundBridgeTarget(GameStateData $state, GamePlayerStateData $player, BoardHexStateData $target): bool
    {
        if ($target->building !== null || $target->terrain === TerrainType::Water || $state->round->phase === GamePhase::Finished) {
            return false;
        }
        $workshops = count(array_filter($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $player->playerId && $hex->building->type === BuildingType::Workshop));
        if ($workshops >= BuildingType::Workshop->supplyLimit()) {
            return false;
        }
        $income = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $incomeRounds = min(2, max(0, 6 - $state->round->number));
        $tools = 1 + $target->terrain->spadesTo($player->homeland) * max(1, 3 - $player->terraformingLevel);

        return $player->resources->tools + $income->tools * $incomeRounds >= $tools
            && $player->resources->coins + $income->coins * $incomeRounds >= 2;
    }

    private function positionScore(GameStateData $state, int $playerId): int
    {
        $player = collect($state->players)->firstWhere('playerId', $playerId);

        if (! $player instanceof GamePlayerStateData) {
            return 0;
        }

        $networkComponents = $this->buildingComponents($state, $playerId);
        $townComponents = [];
        $completedTownHexIds = [];
        $blockedTownExpansionHexIds = [];
        foreach ($networkComponents as $component) {
            if (! array_any($component, static fn (BoardHexStateData $hex): bool => $hex->townId !== null)) {
                $townComponents[] = $component;

                continue;
            }
            foreach ($component as $hex) {
                $completedTownHexIds[$hex->id] = true;
                foreach ($hex->adjacentHexIds as $adjacentHexId) {
                    $blockedTownExpansionHexIds[$adjacentHexId] = true;
                }
            }
        }
        foreach ($state->board->bridges as $bridge) {
            if ($bridge->ownerPlayerId !== $playerId) {
                continue;
            }
            if (isset($completedTownHexIds[$bridge->fromHexId])) {
                $blockedTownExpansionHexIds[$bridge->toHexId] = true;
            }
            if (isset($completedTownHexIds[$bridge->toHexId])) {
                $blockedTownExpansionHexIds[$bridge->fromHexId] = true;
            }
        }
        $ownedBuildingCount = array_sum(array_map('count', $networkComponents));
        $networkCohesion = max(0, $ownedBuildingCount - count($networkComponents));
        $hasUniversity = array_any($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $playerId && $hex->building->type === BuildingType::University);
        $componentScores = array_map(
            function (array $component) use ($state, $player, $hasUniversity, $blockedTownExpansionHexIds): int {
                $power = array_sum(array_map(
                    static fn (BoardHexStateData $hex): int => ($hex->building?->type->powerValue() ?? 0)
                        + ($hex->building?->hasAnnex === true ? 1 : 0),
                    $component,
                ));
                if (! $this->hasTownExpansionSpace($state, $component, $player, $hasUniversity, $blockedTownExpansionHexIds)) {
                    $power = min($power, count($component));
                }
                $annexes = count(array_filter($component, static fn (BoardHexStateData $hex): bool => $hex->building->hasAnnex));
                $minimumBuildings = $this->minimumTownBuildings($component);

                return intdiv(min(self::STANDARD_TOWN_POWER, $power) ** 2
                    * min($minimumBuildings, count($component) + $annexes), $minimumBuildings);
            },
            $townComponents,
        );
        $annexPlanning = $this->townAnnexPlanningEvaluator->execute($state, $player, $townComponents, array_map(
            static fn (int $score): int => $score * self::TOWN_COHESION_WEIGHT,
            $componentScores,
        ));
        $usefulAnnexHexIds = array_fill_keys($annexPlanning->usefulAnnexHexIds, true);
        $townPotential = $this->townAnnexPlanningEvaluator->execute($state, $player, $townComponents, array_map(
            static fn (int $score): int => $score * self::TOWN_COHESION_WEIGHT,
            $componentScores,
        ), valueResourceShortfall: $state->round->number < 6);
        $townCohesion = 0;
        foreach ($townComponents as $index => $component) {
            $unusedAnnexes = count(array_filter($component, static fn (BoardHexStateData $hex): bool =>
                $hex->building->hasAnnex && ! isset($usefulAnnexHexIds[$hex->id])));
            if ($unusedAnnexes === 0) {
                $townCohesion += $componentScores[$index];

                continue;
            }
            $annexes = count(array_filter($component, static fn (BoardHexStateData $hex): bool =>
                $hex->building->hasAnnex && isset($usefulAnnexHexIds[$hex->id])));
            $power = array_sum(array_map(static fn (BoardHexStateData $hex): int =>
                $hex->building->type->powerValue() + (int) ($hex->building->hasAnnex && isset($usefulAnnexHexIds[$hex->id])), $component));
            if (! $this->hasTownExpansionSpace($state, $component, $player, $hasUniversity, $blockedTownExpansionHexIds)) {
                $power = min($power, count($component));
            }
            $minimumBuildings = $this->minimumTownBuildings($component);
            $townCohesion += intdiv(min(self::STANDARD_TOWN_POWER, $power) ** 2
                * min($minimumBuildings, count($component) + $annexes), $minimumBuildings);
        }
        $completedTownHexes = [];
        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $playerId || $hex->townId === null) {
                continue;
            }

            $completedTownHexes[$hex->townId][] = $hex;
        }
        $completedTownPower = [];
        $completedTownAnnexes = 0;
        $requiredPower = $player->palaceId === PalaceAbility::Palace08->value ? 6 : self::STANDARD_TOWN_POWER;
        foreach ($completedTownHexes as $component) {
            $basePower = array_sum(array_map(
                static fn (BoardHexStateData $hex): int => $hex->building->type->powerValue(),
                $component,
            ));
            $annexes = count(array_filter($component, static fn (BoardHexStateData $hex): bool => $hex->building->hasAnnex));
            $neededAnnexes = max(0, $requiredPower - $basePower, $this->minimumTownBuildings($component) - count($component));
            $completedTownAnnexes += min($annexes, $neededAnnexes);
            $completedTownPower[] = $basePower + $annexes;
        }
        $townCohesion += array_sum(array_map(
            static fn (int $power): int => min(self::STANDARD_TOWN_POWER, $power) ** 2,
            $completedTownPower,
        ));
        $unfinishedTownAnnexes = array_sum(array_map(
            static fn (array $component): int => count(array_filter(
                $component,
                static fn (BoardHexStateData $hex): bool => $hex->building->hasAnnex && isset($usefulAnnexHexIds[$hex->id]),
            )),
            $townComponents,
        ));
        $annexWeight = $state->round->phase === GamePhase::Setup
            ? self::USEFUL_TOWN_ANNEX_WEIGHT
            : intdiv(self::USEFUL_TOWN_ANNEX_WEIGHT * max(0, min(2, 6 - $state->round->number)), 2);

        return ($this->reachableHomelandCount($state, $player) * self::REACHABLE_HOMELAND_WEIGHT)
            + $this->expansionOpportunityScore($state, $player)
            + ($this->contestedPositionCount($state, $playerId) * self::CONTESTED_POSITION_WEIGHT)
            + ($this->nearbyOpponentCount($state, $playerId) * self::NEARBY_OPPONENT_WEIGHT)
            + StrategicPriorities::settlementValue($state, $townCohesion * self::TOWN_COHESION_WEIGHT)
            + (($unfinishedTownAnnexes + $completedTownAnnexes) * $annexWeight)
            + StrategicPriorities::settlementValue($state, $townPotential->score)
            - $this->networkBlockingRiskEvaluator->townRisk($state, $player, $townComponents, array_map(
                static fn (int $score): int => $score * self::TOWN_COHESION_WEIGHT,
                $componentScores,
            ), $annexPlanning)
            + ($state->round->phase === GamePhase::Setup ? $this->startingNetworkScore($state, $player, $networkComponents) : 0)
            + $this->startingExpansionSafetyEvaluator->execute($state, $player)
            + ($networkCohesion * self::NETWORK_COHESION_WEIGHT);
    }

    /** @param list<list<BoardHexStateData>> $components */
    private function startingNetworkScore(GameStateData $state, GamePlayerStateData $player, array $components): int
    {
        $hexes = collect($state->board->hexes)->keyBy('id')->all();
        $mergedHousePenalty = 0;
        $expansionAreas = [];
        foreach ($components as $component) {
            $houses = count(array_filter($component, static fn (BoardHexStateData $hex): bool =>
                ! $hex->building->isNeutral && $hex->building->type === BuildingType::Workshop));
            $mergedHousePenalty += max(0, $houses - 1) * self::MERGED_STARTING_HOUSE_PENALTY;
            if ($houses === 0) {
                continue;
            }
            $expansion = [];
            foreach ($component as $hex) {
                foreach ($hex->adjacentHexIds as $neighborId) {
                    $neighbor = $hexes[$neighborId] ?? null;
                    if ($neighbor !== null && $neighbor->building === null && $neighbor->terrain->isHomeland()) {
                        $expansion[$neighborId] = true;
                    }
                }
            }
            foreach ($expansionAreas as $existingArea) {
                if (array_intersect_key($expansion, $existingArea) !== []) {
                    $mergedHousePenalty += self::OVERLAPPING_STARTING_EXPANSION_PENALTY;
                }
            }
            $expansionAreas[] = $expansion;
        }
        if (count($components) < 2) {
            return -$mergedHousePenalty;
        }
        $connected = [0 => true];
        $totalCost = 0;
        while (count($connected) < count($components)) {
            $distances = [];
            foreach (array_keys($connected) as $index) {
                foreach ($components[$index] as $hex) {
                    $distances[$hex->id] = 0;
                }
            }
            $visited = [];
            while ($distances !== []) {
                asort($distances);
                $hexId = array_key_first($distances);
                $cost = $distances[$hexId];
                unset($distances[$hexId]);
                if (isset($visited[$hexId])) {
                    continue;
                }
                $visited[$hexId] = $cost;
                $hex = $hexes[$hexId];
                foreach ([...$hex->adjacentHexIds, ...$hex->riverConnectedHexIds] as $neighborId) {
                    $neighbor = $hexes[$neighborId] ?? null;
                    if ($neighbor === null || isset($visited[$neighborId]) || $neighbor->terrain === TerrainType::Water
                        || ($neighbor->building !== null && $neighbor->building->ownerPlayerId !== $player->playerId)) {
                        continue;
                    }
                    $riverCost = in_array($neighborId, $hex->adjacentHexIds, true) ? 0 : self::FORECAST_RIVER_CONNECTION_COST;
                    $step = $neighbor->building === null ? self::FORECAST_WORKSHOP_COST + $neighbor->terrain->spadesTo($player->homeland) * max(1, 3 - $player->terraformingLevel) : 0;
                    $distances[$neighborId] = min($distances[$neighborId] ?? PHP_INT_MAX, $cost + $step + $riverCost);
                }
            }
            $bestIndex = null;
            $bestCost = PHP_INT_MAX;
            foreach ($components as $index => $component) {
                if (isset($connected[$index])) {
                    continue;
                }
                foreach ($component as $hex) {
                    if (($visited[$hex->id] ?? PHP_INT_MAX) < $bestCost) {
                        $bestCost = $visited[$hex->id];
                        $bestIndex = $index;
                    }
                }
            }
            if ($bestIndex === null) {
                $unreachableCost = count($hexes) * 12 * (count($components) - count($connected));

                return -(int) (($totalCost + $unreachableCost) * self::STARTING_CONNECTION_WEIGHT) - $mergedHousePenalty;
            }
            $connected[$bestIndex] = true;
            $totalCost += $bestCost;
        }

        return -(int) ($totalCost * self::STARTING_CONNECTION_WEIGHT) - $mergedHousePenalty;
    }

    /** @param list<BoardHexStateData> $component */
    private function minimumTownBuildings(array $component): int
    {
        $minimumBuildings = 4;
        foreach ($component as $hex) {
            $minimumBuildings = min($minimumBuildings, match ($hex->building->type) {
                BuildingType::University => 3,
                BuildingType::Monument => 2,
                default => 4,
            });
        }

        return $minimumBuildings;
    }

    /**
     * @param list<BoardHexStateData> $component
     * @param array<string, true> $blockedTownExpansionHexIds
     */
    private function hasTownExpansionSpace(GameStateData $state, array $component, GamePlayerStateData $player, bool $hasUniversity, array $blockedTownExpansionHexIds): bool
    {
        $minimumBuildings = $hasUniversity ? 4 : 3;
        $placedAnnexes = 0;
        $frontier = [];
        foreach ($component as $hex) {
            $placedAnnexes += (int) $hex->building->hasAnnex;
            $minimumBuildings = min($minimumBuildings, match ($hex->building->type) {
                BuildingType::University => 3,
                BuildingType::Monument => 2,
                default => 4,
            });
            $frontier = [...$frontier, ...$hex->adjacentHexIds];
        }
        $missingBuildings = $minimumBuildings - count($component) - $placedAnnexes
            - min($player->availableAnnexes, count($component) - $placedAnnexes);
        if ($missingBuildings <= 0) {
            return true;
        }

        $hexesById = collect($state->board->hexes)->keyBy('id');
        $visited = [];
        while ($frontier !== []) {
            $hexId = array_pop($frontier);
            if (isset($visited[$hexId])) {
                continue;
            }
            $visited[$hexId] = true;
            $hex = $hexesById->get($hexId);
            if (! $hex instanceof BoardHexStateData || $hex->building !== null || ! $hex->terrain->isHomeland()
                || isset($blockedTownExpansionHexIds[$hexId])) {
                continue;
            }
            $missingBuildings--;
            if ($missingBuildings <= 0) {
                return true;
            }
            $frontier = [...$frontier, ...$hex->adjacentHexIds];
        }

        return false;
    }

    private function strandedTerraformingPenalty(GameStateData $before, GameStateData $after, int $playerId): int
    {
        $player = collect($after->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData) {
            return 0;
        }

        $workshopsOnMap = count(array_filter(
            $after->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerId
                && $hex->building->type === BuildingType::Workshop
                && ! $hex->building->isNeutral,
        ));
        if ($workshopsOnMap < BuildingType::Workshop->supplyLimit()) {
            return 0;
        }

        $beforeHexes = collect($before->board->hexes)->keyBy('id');
        $strandedHexCount = count(array_filter(
            $after->board->hexes,
            static function (BoardHexStateData $hex) use ($beforeHexes, $player): bool {
                $beforeHex = $beforeHexes->get($hex->id);

                return $beforeHex instanceof BoardHexStateData
                    && $beforeHex->terrain !== $player->homeland
                    && $hex->terrain === $player->homeland
                    && $hex->building === null;
            },
        ));

        return $strandedHexCount * self::STRANDED_TERRAFORMING_PENALTY;
    }

    private function reachableHomelandCount(GameStateData $state, GamePlayerStateData $player): int
    {
        $reachableHexIds = array_fill_keys($this->findReachableLandHexes->execute($state, $player), true);

        return min(self::MAX_FORECAST_EXPANSION_TARGETS, count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => isset($reachableHexIds[$hex->id])
                && $hex->building === null
                && $hex->terrain === $player->homeland,
        )));
    }

    private function expansionOpportunityScore(GameStateData $state, GamePlayerStateData $player): int
    {
        $hexesById = collect($state->board->hexes)->keyBy('id');
        $reachableHexIds = $this->findReachableLandHexes->execute($state, $player);
        $toolCostPerSpade = max(1, 3 - $player->terraformingLevel);
        $targetScores = [];

        foreach ($reachableHexIds as $hexId) {
            $hex = $hexesById->get($hexId);

            if (! $hex instanceof BoardHexStateData || $hex->building !== null || ! $hex->terrain->isHomeland()) {
                continue;
            }

            $terraformingToolCost = $hex->terrain->spadesTo($player->homeland) * $toolCostPerSpade;
            $targetScore = max(0, 10 - $terraformingToolCost);

            $openDirections = count(array_filter(
                $hex->adjacentHexIds,
                static function (string $adjacentHexId) use ($hexesById): bool {
                    $adjacentHex = $hexesById->get($adjacentHexId);

                    return $adjacentHex instanceof BoardHexStateData
                        && $adjacentHex->building === null
                        && $adjacentHex->terrain->isHomeland();
                },
            ));
            $targetScores[] = $targetScore + min(3, $openDirections) * self::OPEN_DIRECTION_WEIGHT;
        }

        rsort($targetScores, SORT_NUMERIC);

        return array_sum(array_slice($targetScores, 0, self::MAX_FORECAST_EXPANSION_TARGETS));
    }

    private function contestedPositionCount(GameStateData $state, int $playerId): int
    {
        $hexesById = collect($state->board->hexes)->keyBy('id');

        return count(array_filter(
            $state->board->hexes,
            static function (BoardHexStateData $hex) use ($hexesById, $playerId): bool {
                if ($hex->building?->ownerPlayerId !== $playerId) {
                    return false;
                }

                return collect($hex->adjacentHexIds)->contains(
                    static function (string $adjacentHexId) use ($hexesById, $playerId): bool {
                        $ownerPlayerId = $hexesById->get($adjacentHexId)?->building?->ownerPlayerId;

                        return $ownerPlayerId !== null && $ownerPlayerId !== $playerId;
                    },
                );
            },
        ));
    }

    private function nearbyOpponentCount(GameStateData $state, int $playerId): int
    {
        $hexesById = collect($state->board->hexes)->keyBy('id');

        return count(array_filter(
            $state->board->hexes,
            static function (BoardHexStateData $hex) use ($hexesById, $playerId): bool {
                if ($hex->building?->ownerPlayerId !== $playerId) {
                    return false;
                }

                return collect($hex->adjacentHexIds)->contains(
                    static function (string $adjacentHexId) use ($hexesById, $playerId): bool {
                        $adjacentHex = $hexesById->get($adjacentHexId);
                        if (! $adjacentHex instanceof BoardHexStateData || $adjacentHex->building !== null) {
                            return false;
                        }

                        return collect($adjacentHex->adjacentHexIds)->contains(
                            static function (string $nearbyHexId) use ($hexesById, $playerId): bool {
                                $ownerPlayerId = $hexesById->get($nearbyHexId)?->building?->ownerPlayerId;

                                return $ownerPlayerId !== null && $ownerPlayerId !== $playerId;
                            },
                        );
                    },
                );
            },
        ));
    }

    /** @return list<list<BoardHexStateData>> */
    private function buildingComponents(GameStateData $state, int $playerId): array
    {
        return $this->ownedBuildingComponentFinder->execute($state, $playerId);
    }
}
