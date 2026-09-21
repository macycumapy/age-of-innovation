<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\RoundBonus;
use Spatie\LaravelData\Data;

final class ChooseRoundBonusOptionData extends Data implements GameActionOption
{
    public function __construct(
        public RoundBonus $roundBonus,
        public int $coins,
    ) {
    }

    public function type(): string
    {
        return 'choose_round_bonus';
    }
}
