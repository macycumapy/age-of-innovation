<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Actions;

use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class ApplyIncomeAction
{
    public function __construct(private GainPowerAction $gainPower)
    {
    }

    public function execute(
        GameStateData $state,
        GamePlayerStateData $player,
        bool $includeManualResources = true,
    ): IncomeReceiptData {
        $income = PlayerIncomeCalculator::calculate($player, $state->board);
        $scholarsBeforeIncome = $player->resources->scholars;

        $player->resources->tools += $income->tools;
        $player->resources->coins += $income->coins;
        $player->resources->scholars = min(
            $player->scholarPoolSize,
            $player->resources->scholars + $income->scholars,
        );
        if ($includeManualResources) {
            $player->resources->books->unassigned += $income->books;
            $player->knowledge->unassignedSteps += $income->knowledgeSteps;
        }
        $player->victoryPoints += $income->victoryPoints;
        $income->scholars = $player->resources->scholars - $scholarsBeforeIncome;
        $income->power = $this->gainPower->execute($player, $income->power);

        return $income;
    }
}
