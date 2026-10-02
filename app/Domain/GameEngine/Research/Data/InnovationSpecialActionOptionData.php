<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Research\Enums\Innovation;
use Spatie\LaravelData\Data;

final class InnovationSpecialActionOptionData extends Data implements GameActionOption
{
    public function __construct(public Innovation $innovation)
    {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::UseInnovationAction;
    }
}
