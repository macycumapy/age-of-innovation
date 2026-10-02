<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use Spatie\LaravelData\Data;

final class DevelopmentAdvancementResultData extends Data
{
    public function __construct(
        public int $steps,
        public int $books,
        public int $victoryPoints,
    ) {
    }
}
