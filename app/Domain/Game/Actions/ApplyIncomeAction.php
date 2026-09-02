<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Services\PlayerIncomeCalculator;

final class ApplyIncomeAction
{
    public function __construct(private GainPowerAction $gainPower)
    {
    }

    /** @return array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int} */
    public function execute(GameStateData $state, GamePlayerStateData $player): array
    {
        $income = PlayerIncomeCalculator::calculate($player, $state->board);
        $scholarsBeforeIncome = $player->resources->scholars;

        $player->resources->tools += $income['tools'];
        $player->resources->coins += $income['coins'];
        $player->resources->scholars = min(
            $player->scholarPoolSize,
            $player->resources->scholars + $income['scholars'],
        );
        $player->resources->books->unassigned += $income['books'];
        $player->knowledge->unassignedSteps += $income['knowledgeSteps'];
        $player->victoryPoints += $income['victoryPoints'];
        $income['scholars'] = $player->resources->scholars - $scholarsBeforeIncome;
        $income['power'] = $this->gainPower->execute($player, $income['power']);

        return $income;
    }
}
