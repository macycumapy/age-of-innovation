<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\PalaceAbility;
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
