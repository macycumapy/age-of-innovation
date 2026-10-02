<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use Spatie\LaravelData\Data;

final class PowerActionOptionData extends Data implements GameActionOption
{
    public function __construct(
        public PowerAction $action,
        public int $sacrificeAmount,
    ) {
    }

    public function type(): GameActionOptionType
    {
        return GameActionOptionType::PowerAction;
    }
}
