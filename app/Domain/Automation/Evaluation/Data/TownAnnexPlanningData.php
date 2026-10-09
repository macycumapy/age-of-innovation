<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Data;

final readonly class TownAnnexPlanningData
{
    /**
     * @param list<string> $usefulAnnexHexIds
     * @param list<string> $expansionHexIds
     * @param list<string> $annexCandidateHexIds
     */
    public function __construct(
        public int $score,
        public int $projectedTowns,
        public array $usefulAnnexHexIds,
        public int $reservedTools = 0,
        public int $reservedCoins = 0,
        public array $expansionHexIds = [],
        public int $requiredAnnexes = 0,
        public int $remainingActions = 0,
        public array $annexCandidateHexIds = [],
    ) {
    }
}
