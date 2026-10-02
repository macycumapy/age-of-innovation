<?php

declare(strict_types=1);

namespace App\Domain\Game\Contracts;

use App\Domain\Game\Enums\GameActionOptionType;
use Illuminate\Contracts\Support\Arrayable;

/** @extends Arrayable<string, mixed> */
interface GameActionOption extends Arrayable
{
    public function type(): GameActionOptionType;
}
