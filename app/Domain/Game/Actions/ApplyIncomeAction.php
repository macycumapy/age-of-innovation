<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Services\PlayerIncomeCalculator;

final class ApplyIncomeAction
{
    public function execute(GameStateData $state, GamePlayerStateData $player): void
    {
        $income = PlayerIncomeCalculator::calculate($player, $state->board);

        $player->resources->tools += $income['tools'];
        $player->resources->coins += $income['coins'];
        $player->resources->scholars += $income['scholars'];
        $player->resources->books->unassigned += $income['books'];
        $player->knowledge->unassignedSteps += $income['knowledgeSteps'];
        $this->gainPower($player, $income['power']);
    }

    private function gainPower(GamePlayerStateData $player, int $power): void
    {
        for ($step = 0; $step < $power; $step++) {
            if ($player->resources->power->bowlOne > 0) {
                $player->resources->power->bowlOne--;
                $player->resources->power->bowlTwo++;

                continue;
            }

            if ($player->resources->power->bowlTwo > 0) {
                $player->resources->power->bowlTwo--;
                $player->resources->power->bowlThree++;
            }
        }
    }
}
