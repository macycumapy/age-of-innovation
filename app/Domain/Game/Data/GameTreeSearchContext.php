<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

final class GameTreeSearchContext
{
    /** @var array<string, int> */
    public array $cachedScores = [];

    public int $visitedNodes = 0;

    public function __construct(public readonly int $maxNodes)
    {
    }
}
