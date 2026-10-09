<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

use App\Domain\Automation\Enums\GameActionSearchLimit;

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

    /** @phpstan-impure */
    public function isExhausted(): bool
    {
        $nodesExhausted = $this->visitedNodes >= $this->maxNodes;
        $timeExhausted = hrtime(true) >= $this->deadlineNanoseconds;
        if ($nodesExhausted || $timeExhausted) {
            $this->timings->exhaustedBy ??= match (true) {
                $nodesExhausted && $timeExhausted => GameActionSearchLimit::NodesAndTime,
                $nodesExhausted => GameActionSearchLimit::Nodes,
                default => GameActionSearchLimit::Time,
            };
        }

        return $nodesExhausted || $timeExhausted;
    }
}
