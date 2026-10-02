<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Actions;

use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use Illuminate\Validation\ValidationException;

final class ApplySacrificePowerAction
{
    public function execute(GamePlayerStateData $player, int $amount): void
    {
        if ($amount < 1 || $amount * 2 > $player->resources->power->bowlTwo) {
            throw ValidationException::withMessages(['amount' => 'Недостаточно Силы во второй чаше.']);
        }

        $player->resources->power->bowlTwo -= $amount * 2;
        $player->resources->power->bowlThree += $amount;
    }
}
