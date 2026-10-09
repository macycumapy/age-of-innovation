<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Data\StrategicPlanData;
use App\Domain\Automation\Enums\StrategicApproach;
use App\Domain\Automation\Enums\StrategicObjective;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class NetworkConnectionPlanner
{
    private const int MAX_ROUTE_BUILDINGS = 3;

    /** @param list<list<BoardHexStateData>> $components */
    public function execute(GameStateData $state, GamePlayerStateData $player, array $components): ?StrategicPlanData
    {
        $hexes = collect($state->board->hexes)->keyBy('id');
        $componentByHex = [];
        $completedComponents = [];
        foreach ($components as $index => $component) {
            $completedComponents[$index] = array_any($component, static fn (BoardHexStateData $hex): bool => $hex->townId !== null);
            foreach ($component as $hex) {
                $componentByHex[$hex->id] = $index;
            }
        }
        $workshops = count(array_filter($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $player->playerId && ! $hex->building->isNeutral
            && $hex->building->type === BuildingType::Workshop));
        $maxBuildings = min(self::MAX_ROUTE_BUILDINGS, BuildingType::Workshop->supplyLimit() - $workshops);
        if ($maxBuildings < 1) {
            return null;
        }
        $income = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $incomeRounds = min(2, max(0, 6 - $state->round->number));
        $toolBudget = $player->resources->tools + $income->tools * $incomeRounds;
        $coinBudget = $player->resources->coins + $income->coins * $incomeRounds;
        $best = null;
        foreach ($components as $sourceIndex => $source) {
            if ($state->round->number < 6 && $completedComponents[$sourceIndex]
                && count(array_filter($completedComponents)) < 2) {
                continue;
            }
            $frontier = array_map(static fn (BoardHexStateData $hex): array => [$hex->id, [], 0], $source);
            $visited = [];
            while ($frontier !== []) {
                [$hexId, $route, $tools] = array_shift($frontier);
                $hex = $hexes->get($hexId);
                if (! $hex instanceof BoardHexStateData) {
                    continue;
                }
                foreach ($hex->adjacentHexIds as $neighborId) {
                    $neighbor = $hexes->get($neighborId);
                    if (! $neighbor instanceof BoardHexStateData || ! $neighbor->terrain->isHomeland()) {
                        continue;
                    }
                    $destination = $componentByHex[$neighborId] ?? null;
                    if ($destination !== null) {
                        if ($destination === $sourceIndex || $route === []
                            || ($state->round->number < 6 && $completedComponents[$sourceIndex] !== $completedComponents[$destination])) {
                            continue;
                        }
                        $anchors = array_map(static fn (BoardHexStateData $member): string => $member->id, [...$source, ...$components[$destination]]);
                        sort($anchors, SORT_STRING);
                        $size = count($anchors) + count($route);
                        $score = 160 + $size * 20 - $tools * 4 - count($route) * 4 - (count($route) - 1) * 16;
                        if ($best === null || $score > $best->score) {
                            $best = new StrategicPlanData(
                                StrategicObjective::ConnectNetwork,
                                $anchors,
                                $route,
                                $tools,
                                count($route) * 2,
                                0,
                                $score,
                                count($route),
                                $route[array_key_last($route)],
                                approach: StrategicApproach::Build,
                            );
                        }
                        continue;
                    }
                    if ($neighbor->building !== null || count($route) >= $maxBuildings || in_array($neighborId, $route, true)) {
                        continue;
                    }
                    if ($state->round->number < 6 && array_any($neighbor->adjacentHexIds, static fn (string $id): bool =>
                        isset($componentByHex[$id]) && $completedComponents[$componentByHex[$id]] !== $completedComponents[$sourceIndex])) {
                        continue;
                    }
                    $nextTools = $tools + 1 + $neighbor->terrain->spadesTo($player->homeland) * max(1, 3 - $player->terraformingLevel);
                    $nextLength = count($route) + 1;
                    $key = $neighborId.':'.$nextLength;
                    if ($nextTools > $toolBudget || $nextLength * 2 > $coinBudget || (isset($visited[$key]) && $visited[$key] <= $nextTools)) {
                        continue;
                    }
                    $visited[$key] = $nextTools;
                    $frontier[] = [$neighborId, [...$route, $neighborId], $nextTools];
                }
            }
        }

        return $best;
    }
}
