<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

use App\Domain\Automation\Enums\GameActionSelectionReason;

final readonly class GameActionSelectionDiagnosticsData
{
    /** @param list<GameActionCandidateDiagnosticsData> $candidates */
    public function __construct(
        public ?EvaluatedGameActionData $selected,
        public array $candidates,
        public GameActionSelectionReason $selectionReason,
        public int $visitedNodes,
        public int $durationMilliseconds,
        public bool $budgetExhausted,
        public GameActionSearchTimingsData $searchTimings,
    ) {
    }
}
