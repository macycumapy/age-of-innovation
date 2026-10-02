<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Contracts;

use App\Domain\GameEngine\Enums\GameActionOptionType;
use Illuminate\Contracts\Support\Arrayable;

/** @extends Arrayable<string, mixed> */
interface GameActionOption extends Arrayable
{
    public function type(): GameActionOptionType;
}
