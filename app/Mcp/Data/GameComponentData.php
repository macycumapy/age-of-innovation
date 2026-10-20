<?php

declare(strict_types=1);

namespace App\Mcp\Data;

use App\Mcp\Enums\GameComponentType;

final readonly class GameComponentData
{
    public function __construct(public GameComponentType $type, public string $id)
    {
    }
}
