<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\RoundBonus;
use Spatie\LaravelData\Data;

/**
 * @property RoundBonus $roundBonus Доступный для выбора бонус раунда.
 * @property int $coins Число накопленных на бонусе монет.
 */
final class RoundBonusOfferData extends Data
{
    public function __construct(
        public RoundBonus $roundBonus,
        public int $coins = 1,
    ) {
    }
}
