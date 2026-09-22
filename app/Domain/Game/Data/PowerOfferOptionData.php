<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
use Spatie\LaravelData\Data;

final class PowerOfferOptionData extends Data implements GameActionOption
{
    public function __construct(public bool $accept)
    {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::ResolvePowerOffer;
    }
}
