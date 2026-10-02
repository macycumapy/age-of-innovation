<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Towns\Enums\TownTile;
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
