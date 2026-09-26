<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

final class GameTreeSearchContext
{
    /** @var array<string, int> */
    public array $cachedScores = [];

    public int $visitedNodes = 0;

    public readonly int $deadlineNanoseconds;

    public function __construct(
        public readonly int $maxNodes,
        int $maxTimeMilliseconds,
    ) {
        $this->deadlineNanoseconds = hrtime(true) + ($maxTimeMilliseconds * 1_000_000);
    }

    public function isExhausted(): bool
    {
        return $this->visitedNodes >= $this->maxNodes || hrtime(true) >= $this->deadlineNanoseconds;
    }
}
