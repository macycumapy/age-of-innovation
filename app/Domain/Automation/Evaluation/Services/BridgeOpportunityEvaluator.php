<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Services\BuildingAdjacencyChecker;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class BridgeOpportunityEvaluator
{
    public function __construct(private OwnedBuildingComponentFinder $componentFinder)
    {
    }

    public function execute(GameStateData $state, GamePlayerStateData $player, string $fromHexId, string $toHexId): bool
    {
        $hexes = collect($state->board->hexes)->keyBy('id');
        $from = $hexes->get($fromHexId);
        $to = $hexes->get($toHexId);
        if (! $from instanceof BoardHexStateData || ! $to instanceof BoardHexStateData) {
            return false;
        }
        if ($from->building?->ownerPlayerId !== $player->playerId) {
            [$from, $to] = [$to, $from];
        }
        if ($from->building?->ownerPlayerId !== $player->playerId) {
            return false;
        }
        $component = [];
        foreach ($this->componentFinder->execute($state, $player->playerId) as $members) {
            if (array_any($members, static fn (BoardHexStateData $hex): bool => $hex->id === $from->id)) {
                $component = $members;
                break;
            }
        }
        if (array_any($component, static fn (BoardHexStateData $hex): bool => $hex->id === $to->id)) {
            return false;
        }
        if ($to->building?->ownerPlayerId === $player->playerId) {
            return true;
        }
        $income = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $rounds = min(2, max(0, 6 - $state->round->number));
        $tools = $player->resources->tools + $income->tools * $rounds;
        $coins = $player->resources->coins + $income->coins * $rounds;
        if ($to->building !== null) {
            return $from->building->type === BuildingType::Workshop
                && ! BuildingAdjacencyChecker::hasOpponent($state->board, $from, $player->playerId)
                && $tools >= 2 && $coins >= 3;
        }
        $usedWorkshops = count(array_filter($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $player->playerId && ! $hex->building->isNeutral
            && $hex->building->type === BuildingType::Workshop));

        return $to->terrain->isHomeland() && $usedWorkshops < BuildingType::Workshop->supplyLimit()
            && ! array_any($component, static fn (BoardHexStateData $hex): bool => in_array($to->id, $hex->adjacentHexIds, true))
            && $tools >= 1 + $to->terrain->spadesTo($player->homeland) * max(1, 3 - $player->terraformingLevel)
            && $coins >= 2;
    }
}
