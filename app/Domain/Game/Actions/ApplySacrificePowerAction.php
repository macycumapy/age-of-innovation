<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
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
