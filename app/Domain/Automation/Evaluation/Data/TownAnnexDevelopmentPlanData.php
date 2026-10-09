<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Data;

final readonly class TownAnnexDevelopmentPlanData
{
    /**
     * @param list<string> $usefulAnnexHexIds
     * @param list<string> $expansionHexIds
     * @param list<string> $annexCandidateHexIds
     */
    public function __construct(
        public int $power = 0,
        public int $minimumBuildings = 4,
        public int $requiredAnnexes = 0,
        public int $tools = 0,
        public int $terraformingTools = 0,
        public int $coins = 0,
        public int $upgrades = 0,
        public int $guilds = 0,
        public int $palaces = 0,
        public int $universities = 0,
        public int $workshops = 0,
        public int $score = 0,
        public int $towns = 0,
        public array $usefulAnnexHexIds = [],
        public array $expansionHexIds = [],
        public array $annexCandidateHexIds = [],
    ) {
    }
}
