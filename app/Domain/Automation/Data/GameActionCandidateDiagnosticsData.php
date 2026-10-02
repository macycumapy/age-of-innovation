<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;

final readonly class GameActionCandidateDiagnosticsData
{
    public function __construct(
        public GameActionOptionType $type,
        public int $score,
        public int $rank,
        public int $scoreDelta,
        public bool $selected,
        public GameActionScoreData $scoreBreakdown,
        public GameActionOption $option,
    ) {
    }
}
