<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

final class GameTreeSearchContext
{
    public GameActionSearchTimingsData $timings;
    /** @var array<string, int> */
    public array $cachedScores = [];

    public int $visitedNodes = 0;

    public readonly int $deadlineNanoseconds;

    public function __construct(
        public readonly int $maxNodes,
        int $maxTimeMilliseconds,
    ) {
        $this->timings = new GameActionSearchTimingsData();
        $this->deadlineNanoseconds = hrtime(true) + ($maxTimeMilliseconds * 1_000_000);
    }

    public function isExhausted(): bool
    {
        return $this->visitedNodes >= $this->maxNodes || hrtime(true) >= $this->deadlineNanoseconds;
    }
}
