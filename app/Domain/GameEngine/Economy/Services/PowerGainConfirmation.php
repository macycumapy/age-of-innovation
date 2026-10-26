<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Services;

use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use Illuminate\Container\Attributes\Scoped;
use Illuminate\Validation\ValidationException;

#[Scoped]
final class PowerGainConfirmation
{
    public ?int $userId = null;

    /** @var list<string> */
    public array $acknowledged = [];

    public function validate(GamePlayerStateData $player, int $amount): void
    {
        $capacity = $player->resources->power->bowlOne * 2 + $player->resources->power->bowlTwo;

        if ($this->userId !== $player->userId || $amount <= $capacity) {
            return;
        }

        $key = $player->playerId.':'.$amount.':'.$capacity;

        if (in_array($key, $this->acknowledged, true)) {
            return;
        }

        throw ValidationException::withMessages([
            'power_confirmation' => "Вы получите {$capacity} из {$amount} Силы. Пропадёт ".($amount - $capacity).' Силы. Всё равно выполнить действие?',
            'power_confirmation_key' => $key,
        ]);
    }
}
