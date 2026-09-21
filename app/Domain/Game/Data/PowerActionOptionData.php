<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Enums\PowerAction;
use Spatie\LaravelData\Data;

final class PowerActionOptionData extends Data implements GameActionOption
{
    public function __construct(
        public PowerAction $action,
        public int $sacrificeAmount,
    ) {
    }

    public function type(): string
    {
        return 'power_action';
    }
}
