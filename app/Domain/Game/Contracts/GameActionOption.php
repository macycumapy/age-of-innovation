<?php

declare(strict_types=1);

namespace App\Domain\Game\Contracts;

use App\Domain\Game\Enums\GameActionOptionType;

interface GameActionOption
{
    public function type(): GameActionOptionType;
}
