<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Data;

final readonly class GameStateScoreData
{
    public function __construct(
        public int $victoryPoints,
        public int $resources,
        public int $development,
        public int $buildings,
        public int $futureIncome,
        public int $opponent = 0,
    ) {
    }

    public function total(): int
    {
        return $this->victoryPoints + $this->resources + $this->development
            + $this->buildings + $this->futureIncome - $this->opponent;
    }
}
