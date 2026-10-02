<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Actions;

use App\Domain\GameEngine\State\Data\GamePlayerStateData;

final class GainPowerAction
{
    public function execute(GamePlayerStateData $player, int $amount): int
    {
        $gainedPower = 0;

        for ($step = 0; $step < $amount; $step++) {
            if ($player->resources->power->bowlOne > 0) {
                $player->resources->power->bowlOne--;
                $player->resources->power->bowlTwo++;
                $gainedPower++;

                continue;
            }

            if ($player->resources->power->bowlTwo > 0) {
                $player->resources->power->bowlTwo--;
                $player->resources->power->bowlThree++;
                $gainedPower++;
            }
        }

        return $gainedPower;
    }
}
