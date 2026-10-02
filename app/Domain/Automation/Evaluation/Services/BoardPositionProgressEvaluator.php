<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Actions\FindReachableLandHexesAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

class BoardPositionProgressEvaluator
{
    private const int REACHABLE_HOMELAND_WEIGHT = 3;

    private const int TOWN_COHESION_WEIGHT = 2;

    private const int NETWORK_COHESION_WEIGHT = 10;

    private const int OPEN_DIRECTION_WEIGHT = 2;

    private const int CONTESTED_POSITION_WEIGHT = 5;

    public function __construct(private FindReachableLandHexesAction $findReachableLandHexes)
    {
    }

    public function execute(GameStateData $before, GameStateData $after, int $playerId): int
    {
        return $this->positionScore($after, $playerId) - $this->positionScore($before, $playerId);
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

                return $power ** 2;
            },
            $townComponents,
        ));

        return ($this->reachableHomelandCount($state, $player) * self::REACHABLE_HOMELAND_WEIGHT)
            + $this->expansionOpportunityScore($state, $player)
            + ($this->contestedPositionCount($state, $playerId) * self::CONTESTED_POSITION_WEIGHT)
            + ($townCohesion * self::TOWN_COHESION_WEIGHT)
            + ($networkCohesion * self::NETWORK_COHESION_WEIGHT);
    }

    private function reachableHomelandCount(GameStateData $state, GamePlayerStateData $player): int
    {
        $reachableHexIds = array_fill_keys($this->findReachableLandHexes->execute($state, $player), true);

        return count(array_filter(
            $state->board->hexes,
            static fn (BoardHexStateData $hex): bool => isset($reachableHexIds[$hex->id])
                && $hex->building === null
                && $hex->terrain === $player->homeland,
        ));
    }

    private function expansionOpportunityScore(GameStateData $state, GamePlayerStateData $player): int
    {
        $hexesById = collect($state->board->hexes)->keyBy('id');
        $reachableHexIds = $this->findReachableLandHexes->execute($state, $player);
        $toolCostPerSpade = max(1, 3 - $player->terraformingLevel);
        $score = 0;

        foreach ($reachableHexIds as $hexId) {
            $hex = $hexesById->get($hexId);

            if (! $hex instanceof BoardHexStateData || $hex->building !== null || ! $hex->terrain->isHomeland()) {
                continue;
            }

            $terraformingToolCost = $hex->terrain->spadesTo($player->homeland) * $toolCostPerSpade;
            $score += max(0, 10 - $terraformingToolCost);

            $openDirections = count(array_filter(
                $hex->adjacentHexIds,
                static function (string $adjacentHexId) use ($hexesById): bool {
                    $adjacentHex = $hexesById->get($adjacentHexId);

                    return $adjacentHex instanceof BoardHexStateData
                        && $adjacentHex->building === null
                        && $adjacentHex->terrain->isHomeland();
                },
            ));
            $score += min(3, $openDirections) * self::OPEN_DIRECTION_WEIGHT;
        }

        return $score;
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
