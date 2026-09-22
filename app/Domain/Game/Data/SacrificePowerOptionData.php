<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
use Spatie\LaravelData\Data;

final class SacrificePowerOptionData extends Data implements GameActionOption
{
    public function __construct(public int $amount = 1)
    {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::SacrificePower;
    }
}
