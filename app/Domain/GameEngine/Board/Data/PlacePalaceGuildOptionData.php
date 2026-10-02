<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use Spatie\LaravelData\Data;

final class PlacePalaceGuildOptionData extends Data implements GameActionOption
{
    public function __construct(public string $hexId)
    {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::PlacePalaceGuild;
    }
}
