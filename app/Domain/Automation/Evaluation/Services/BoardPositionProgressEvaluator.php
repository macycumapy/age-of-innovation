<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Actions\FindReachableLandHexesAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

class BoardPositionProgressEvaluator
{
    private const int REACHABLE_HOMELAND_WEIGHT = 3;

    private const int TOWN_COHESION_WEIGHT = 8;

    private const int STANDARD_TOWN_POWER = 7;

    private const int NETWORK_COHESION_WEIGHT = 10;

    private const int OPEN_DIRECTION_WEIGHT = 2;

    private const int CONTESTED_POSITION_WEIGHT = 5;

    private const int NEARBY_OPPONENT_WEIGHT = 6;

    private const int STRANDED_TERRAFORMING_PENALTY = 40;

    private const int MAX_FORECAST_EXPANSION_TARGETS = 3;

    public function __construct(private FindReachableLandHexesAction $findReachableLandHexes)
    {
    }

    public function execute(GameStateData $before, GameStateData $after, int $playerId): int
    {
        return $this->positionScore($after, $playerId)
            - $this->positionScore($before, $playerId)
            - $this->strandedTerraformingPenalty($before, $after, $playerId);
    }

    private function positionScore(GameStateData $state, int $playerId): int
    {
        $player = collect($state->players)->firstWhere('playerId', $playerId);

        if (! $player instanceof GamePlayerStateData) {
            return 0;
        }

        $networkComponents = $this->buildingComponents($state, $playerId);
        $townComponents = $this->buildingComponents($state, $playerId, excludeTowns: true);
        $ownedBuildingCount = array_sum(array_map('count', $networkComponents));
        $networkCohesion = max(0, $ownedBuildingCount - count($networkComponents));
        $townCohesion = array_sum(array_map(
            static function (array $component): int {
                $power = array_sum(array_map(
                    static fn (BoardHexStateData $hex): int => ($hex->building?->type->powerValue() ?? 0)
                        + ($hex->building?->hasAnnex === true ? 1 : 0),
                    $component,
                ));

                return min(self::STANDARD_TOWN_POWER, $power) ** 2;
            },
            $townComponents,
        ));
        $completedTownPower = [];
        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $playerId || $hex->townId === null) {
                continue;
            }

            $completedTownPower[$hex->townId] = ($completedTownPower[$hex->townId] ?? 0)
                + $hex->building->type->powerValue() + (int) $hex->building->hasAnnex;
        }
        $townCohesion += array_sum(array_map(
            static fn (int $power): int => min(self::STANDARD_TOWN_POWER, $power) ** 2,
            $completedTownPower,
        ));

        return ($this->reachableHomelandCount($state, $player) * self::REACHABLE_HOMELAND_WEIGHT)
            + $this->expansionOpportunityScore($state, $player)
            + ($this->contestedPositionCount($state, $playerId) * self::CONTESTED_POSITION_WEIGHT)
            + ($this->nearbyOpponentCount($state, $playerId) * self::NEARBY_OPPONENT_WEIGHT)
            + ($townCohesion * self::TOWN_COHESION_WEIGHT)
            + ($networkCohesion * self::NETWORK_COHESION_WEIGHT);
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
    private function buildingComponents(GameStateData $state, int $playerId, bool $excludeTowns = false): array
    {
        $ownedHexes = collect($state->board->hexes)
            ->filter(static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerId
                && (! $excludeTowns || $hex->townId === null))
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
