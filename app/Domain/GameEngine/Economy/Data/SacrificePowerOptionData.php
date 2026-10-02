<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
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
