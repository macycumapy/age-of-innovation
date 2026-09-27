<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

final readonly class AutomatedGameResourcesData
{
    public function __construct(
        public int $coins,
        public int $tools,
        public int $scholars,
        public int $books,
        public int $power,
        public int $spades,
    ) {
    }
}
