<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class StartingExpansionSafetyEvaluator
{
    private const int MAX_PATH_LENGTH = 3;

    private const int MISSING_EXIT_PENALTY = 30;

    private const int EXIT_COST_WEIGHT = 2;

    private const int FUTURE_SHIPPING_COST = 4;

    private const int EDGE_START_PENALTY = 18;

    private const int CORNER_MISSING_NEIGHBOR_WEIGHT = 12;

    public function execute(GameStateData $state, GamePlayerStateData $player): int
    {
        if ($state->round->phase !== GamePhase::Setup) {
            return 0;
        }
        $hexes = [];
        foreach ($state->board->hexes as $hex) {
            $hexes[$hex->id] = $hex;
        }
        $score = 0;
        $edgeHexIds = array_fill_keys($state->board->edgeHexIds, true);
        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId !== $player->playerId || $hex->building->isNeutral) {
                continue;
            }
            if (isset($edgeHexIds[$hex->id])) {
                $neighborCount = count(array_filter(
                    array_unique($hex->adjacentHexIds),
                    static fn (string $hexId): bool => isset($hexes[$hexId])
                ));
                $score -= self::EDGE_START_PENALTY
                    + max(0, 6 - $neighborCount - 2) * self::CORNER_MISSING_NEIGHBOR_WEIGHT;
            }
            $usedHexIds = [];
            $exitCount = 0;
            $exitCost = 0;
            for ($exit = 0; $exit < 2; $exit++) {
                $route = $this->escapeRoute($hex, $hexes, $player, $usedHexIds);
                if ($route === null) {
                    break;
                }
                $exitCount++;
                $exitCost += $route['cost'];
                foreach ($route['path'] as $hexId) {
                    $usedHexIds[$hexId] = true;
                }
            }
            $score -= (2 - $exitCount) * self::MISSING_EXIT_PENALTY + $exitCost * self::EXIT_COST_WEIGHT;
        }

        return $score;
    }

    /**
     * @param array<string, BoardHexStateData> $hexes
     * @param array<string, true> $excludedHexIds
     * @return array{cost: int, path: list<string>}|null
     */
    private function escapeRoute(BoardHexStateData $origin, array $hexes, GamePlayerStateData $player, array $excludedHexIds): ?array
    {
        $frontier = [['cost' => 0, 'path' => [], 'hexId' => $origin->id]];
        $bestCosts = [];
        while ($frontier !== []) {
            usort($frontier, static fn (array $left, array $right): int => $right['cost'] <=> $left['cost']);
            $route = array_pop($frontier);
            $hex = $hexes[$route['hexId']];
            $length = count($route['path']);
            if ($length >= 2) {
                $openNeighbors = array_filter($hex->adjacentHexIds, static fn (string $hexId): bool =>
                    isset($hexes[$hexId]) && $hexes[$hexId]->building === null && $hexes[$hexId]->terrain->isHomeland());
                if (count($openNeighbors) >= 3) {
                    return ['cost' => $route['cost'], 'path' => $route['path']];
                }
            }
            if ($length >= self::MAX_PATH_LENGTH) {
                continue;
            }
            foreach (array_unique([...$hex->adjacentHexIds, ...$hex->riverConnectedHexIds]) as $hexId) {
                $neighbor = $hexes[$hexId] ?? null;
                if ($neighbor === null || $neighbor->building !== null || $neighbor->terrain === TerrainType::Water
                    || isset($excludedHexIds[$hexId]) || in_array($hexId, $route['path'], true)) {
                    continue;
                }
                $riverCost = in_array($hexId, $hex->adjacentHexIds, true) || $player->shippingLevel > 0
                    ? 0 : self::FUTURE_SHIPPING_COST;
                $cost = $route['cost'] + 1 + $neighbor->terrain->spadesTo($player->homeland)
                    * max(1, 3 - $player->terraformingLevel) + $riverCost;
                $key = $hexId.':'.($length + 1);
                if (($bestCosts[$key] ?? PHP_INT_MAX) <= $cost) {
                    continue;
                }
                $bestCosts[$key] = $cost;
                $frontier[] = ['cost' => $cost, 'path' => [...$route['path'], $hexId], 'hexId' => $hexId];
            }
        }

        return null;
    }
}
