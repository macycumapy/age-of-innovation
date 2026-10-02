<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

final readonly class GameActionScoreData
{
    public function __construct(
        public GameStateScoreData $state,
        public int $roundScoring,
        public int $finalScoring,
        public int $boardPosition,
        public int $economicNeeds,
        public int $passPenalty,
        public int $searchAdjustment = 0,
    ) {
    }

    public function strategicProgress(): int
    {
        return $this->roundScoring + $this->finalScoring + $this->boardPosition + $this->economicNeeds;
    }

    public function total(): int
    {
        return $this->state->total() + $this->strategicProgress() + $this->searchAdjustment - $this->passPenalty;
    }
}
