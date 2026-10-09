<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Data;

final readonly class TownAnnexPlanningData
{
    /** @param list<string> $usefulAnnexHexIds */
    public function __construct(
        public int $score,
        public int $projectedTowns,
        public array $usefulAnnexHexIds,
    ) {
    }
}
