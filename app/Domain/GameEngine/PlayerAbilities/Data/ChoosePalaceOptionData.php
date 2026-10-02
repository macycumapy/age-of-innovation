<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use Spatie\LaravelData\Data;

final class ChoosePalaceOptionData extends Data implements GameActionOption
{
    public function __construct(public PalaceAbility $palace)
    {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::ChoosePalace;
    }
}
