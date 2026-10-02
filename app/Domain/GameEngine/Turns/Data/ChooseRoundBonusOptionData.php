<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Turns\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use Spatie\LaravelData\Data;

final class ChooseRoundBonusOptionData extends Data implements GameActionOption
{
    public function __construct(
        public RoundBonus $roundBonus,
        public int $coins,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::ChooseRoundBonus;
    }
}
