<?php

declare(strict_types=1);

namespace App\Mcp\Data;

final readonly class GameQueryData
{
    public function __construct(
        public ?int $gameId,
    ) {
    }
}
