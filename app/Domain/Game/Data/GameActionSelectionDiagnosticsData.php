<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

final readonly class GameActionSelectionDiagnosticsData
{
    /**
     * @param list<array{type: string, score: int}> $candidates
     */
    public function __construct(
        public ?EvaluatedGameActionData $selected,
        public array $candidates,
        public int $visitedNodes,
        public int $durationMilliseconds,
        public bool $budgetExhausted,
    ) {
    }
}
