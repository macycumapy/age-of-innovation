<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\TownTile;
use Spatie\LaravelData\Data;

final class ChooseTownOptionData extends Data implements GameActionOption
{
    public function __construct(public TownTile $townTile)
    {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::ChooseTown;
    }
}
