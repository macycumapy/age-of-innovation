<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Actions\FindReachableLandHexesAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
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

    public function __construct(
        private FindReachableLandHexesAction $findReachableLandHexes,
        private NetworkBlockingRiskEvaluator $networkBlockingRiskEvaluator,
        private TownAnnexPlanningEvaluator $townAnnexPlanningEvaluator,
    ) {
    }

    public function execute(GameStateData $before, GameStateData $after, int $playerId): int
    {
        return $this->positionScore($after, $playerId)
            - $this->positionScore($before, $playerId)
            - $this->strandedTerraformingPenalty($before, $after, $playerId)
            + $this->networkBlockingRiskEvaluator->execute($before, $after, $playerId);
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
            + ($townCohesion * self::TOWN_COHESION_WEIGHT)
            + (($unfinishedTownAnnexes + $completedTownAnnexes) * $annexWeight)
            + $annexPlanning->score
            + ($networkCohesion * self::NETWORK_COHESION_WEIGHT);
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
        $ownedHexes = collect($state->board->hexes)
            ->filter(static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerId)
            ->keyBy('id')
            ->all();
        $bridgeConnections = [];

        foreach ($state->board->bridges as $bridge) {
            if ($bridge->ownerPlayerId === $playerId) {
                $bridgeConnections[$bridge->fromHexId][] = $bridge->toHexId;
                $bridgeConnections[$bridge->toHexId][] = $bridge->fromHexId;
            }
        }

        $components = [];
        $visited = [];

        foreach (array_keys($ownedHexes) as $startingHexId) {
            if (isset($visited[$startingHexId])) {
                continue;
            }

            $component = [];
            $frontier = [$startingHexId];

            while ($frontier !== []) {
                $hexId = array_pop($frontier);

                if (isset($visited[$hexId]) || ! isset($ownedHexes[$hexId])) {
                    continue;
                }

                $visited[$hexId] = true;
                $hex = $ownedHexes[$hexId];
                $component[] = $hex;

                foreach ([...$hex->adjacentHexIds, ...($bridgeConnections[$hexId] ?? [])] as $connectedHexId) {
                    if (isset($ownedHexes[$connectedHexId]) && ! isset($visited[$connectedHexId])) {
                        $frontier[] = $connectedHexId;
                    }
                }
            }

            $components[] = $component;
        }

        return $components;
    }
}
