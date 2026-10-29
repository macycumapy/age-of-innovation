<?php

declare(strict_types=1);

namespace App\Mcp\Data;

final readonly class ExecuteGameActionData
{
    public function __construct(
        public ?int $gameId,
        public int $version,
        public string $actionId,
    ) {
    }
}
