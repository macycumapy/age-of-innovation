<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

final readonly class AutomatedGameSimulationResultData
{
    /**
     * @param list<AutomatedGameDecisionData> $decisions
     * @param array<int, int> $finalScores
     */
    public function __construct(
        public bool $completed,
        public array $decisions,
        public int $durationMilliseconds,
        public array $finalScores,
        public ?string $stoppedReason = null,
    ) {
    }
}
