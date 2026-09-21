<?php

declare(strict_types=1);

namespace App\Domain\Game\Contracts;

interface GameActionOption
{
    public function type(): string;
}
