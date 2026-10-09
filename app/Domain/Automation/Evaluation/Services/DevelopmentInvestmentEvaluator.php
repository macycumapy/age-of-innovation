<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Actions\FindReachableLandHexesAction;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class DevelopmentInvestmentEvaluator
{
    private const int MAX_FORECAST_HOUSES = 3;

    private const int SAVED_TOOL_WEIGHT = 20;

    private const int UNLOCKED_CONSTRUCTION_WEIGHT = 40;

    private const int EXPANSION_SPADE_COST_WEIGHT = 8;

    private const int OPEN_DIRECTION_WEIGHT = 8;

    public function __construct(private FindReachableLandHexesAction $findReachableLandHexes)
    {
    }

    public function execute(GameStateData $before, GameStateData $after, int $playerId): int
    {
        if (! $before->round->phase->isActionPhase() || $before->pendingInteraction !== null) {
            return 0;
        }
        $previous = collect($before->players)->firstWhere('playerId', $playerId);
        $player = collect($after->players)->firstWhere('playerId', $playerId);
        if (! $previous instanceof GamePlayerStateData || ! $player instanceof GamePlayerStateData) {
            return 0;
        }
        if ($player->shippingLevel > $previous->shippingLevel) {
            return max(0, $this->fundedExpansionValue($after, $player) - $this->fundedExpansionValue($before, $previous));
        }
        if ($player->terraformingLevel <= $previous->terraformingLevel) {
            return 0;
        }
        $placed = 0;
        $spadeCosts = [];
        $reachable = array_fill_keys($this->findReachableLandHexes->execute($after, $player), true);
        foreach ($after->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId === $playerId && $hex->building->type === BuildingType::Workshop && ! $hex->building->isNeutral) {
                $placed++;
            }
            if (isset($reachable[$hex->id]) && $hex->building === null && $hex->townId === null && $hex->terrain->isHomeland()) {
                $spadeCosts[] = $hex->terrain->spadesTo($player->homeland);
            }
        }
        sort($spadeCosts, SORT_NUMERIC);
        $income = PlayerIncomeCalculator::calculate($player, $after->board, includeRoundBonus: false);
        $rounds = min(2, max(0, 6 - $after->round->number));
        $tools = $player->resources->tools + $income->tools * $rounds;
        $coins = $player->resources->coins + $income->coins * $rounds;
        $freeSpades = $player->unassignedSpades;
        if ($player->roundBonus === RoundBonus::Spade && ! in_array(RoundBonus::Spade->value, $player->usedSpecialActionIds, true)) {
            $freeSpades++;
        }
        $limit = min(self::MAX_FORECAST_HOUSES, max(0, BuildingType::Workshop->supplyLimit() - $placed));
        $paidSpades = 0;
        $costPerSpade = max(1, 3 - $player->terraformingLevel);
        foreach (array_slice($spadeCosts, 0, $limit) as $spades) {
            $paid = max(0, $spades - $freeSpades);
            $cost = 1 + $paid * $costPerSpade;
            if ($cost > $tools || $coins < 2) {
                break;
            }
            $tools -= $cost;
            $coins -= 2;
            $freeSpades = max(0, $freeSpades - $spades);
            $paidSpades += $paid;
        }

        return $paidSpades * (max(1, 3 - $previous->terraformingLevel) - $costPerSpade) * self::SAVED_TOOL_WEIGHT;
    }

    private function fundedExpansionValue(GameStateData $state, GamePlayerStateData $player): int
    {
        $placed = 0;
        $reachable = array_fill_keys($this->findReachableLandHexes->execute($state, $player), true);
        $bestValue = 0;
        $hexes = collect($state->board->hexes)->keyBy('id');
        $income = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $rounds = min(2, max(0, 6 - $state->round->number));
        $tools = $player->resources->tools + $income->tools * $rounds;
        $coins = $player->resources->coins + $income->coins * $rounds;
        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId === $player->playerId && $hex->building->type === BuildingType::Workshop && ! $hex->building->isNeutral) {
                $placed++;
            }
            if (isset($reachable[$hex->id]) && $hex->building === null && $hex->townId === null && $hex->terrain->isHomeland()
                && $coins >= 2) {
                $spades = max(0, $hex->terrain->spadesTo($player->homeland) - $player->unassignedSpades);
                $cost = 1 + $spades * max(1, 3 - $player->terraformingLevel);
                if ($tools < $cost) {
                    continue;
                }
                $neighbors = $hex->adjacentHexIds;
                foreach ($state->board->bridges as $bridge) {
                    if ($bridge->ownerPlayerId === $player->playerId) {
                        if ($bridge->fromHexId === $hex->id) {
                            $neighbors[] = $bridge->toHexId;
                        } elseif ($bridge->toHexId === $hex->id) {
                            $neighbors[] = $bridge->fromHexId;
                        }
                    }
                }
                $joinsTown = false;
                $joinsUnfinishedGroup = false;
                $openDirections = 0;
                foreach (array_unique($neighbors) as $neighborId) {
                    $neighbor = $hexes->get($neighborId);
                    if ($neighbor?->building?->ownerPlayerId === $player->playerId) {
                        $joinsTown = $joinsTown || $neighbor->townId !== null;
                        $joinsUnfinishedGroup = $joinsUnfinishedGroup || $neighbor->townId === null;
                    } elseif ($neighbor !== null && $neighbor->building === null && $neighbor->terrain->isHomeland()) {
                        $openDirections++;
                    }
                }
                if ($joinsTown && ! $joinsUnfinishedGroup) {
                    continue;
                }
                $value = max(0, self::UNLOCKED_CONSTRUCTION_WEIGHT - $spades * self::EXPANSION_SPADE_COST_WEIGHT)
                    + min(3, $openDirections) * self::OPEN_DIRECTION_WEIGHT;
                if ($player->resources->tools < $cost || $player->resources->coins < 2) {
                    $value = intdiv($value, 2);
                }
                $bestValue = max($bestValue, $value);
            }
        }

        return $placed < BuildingType::Workshop->supplyLimit() ? $bestValue : 0;
    }
}
