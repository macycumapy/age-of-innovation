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

    public function execute(GameStateData $state, GamePlayerStateData $player): void
    {
        $income = PlayerIncomeCalculator::calculate($player, $state->board);

        $player->resources->tools += $income['tools'];
        $player->resources->coins += $income['coins'];
        $player->resources->scholars = min(
            $player->scholarPoolSize,
            $player->resources->scholars + $income['scholars'],
        );
        $player->resources->books->unassigned += $income['books'];
        $player->knowledge->unassignedSteps += $income['knowledgeSteps'];
        $this->gainPower->execute($player, $income['power']);
    }
}
