<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Contracts\GameActionOption;

final readonly class EvaluatedGameActionData
{
    public function __construct(
        public GameActionOption $option,
        public GameActionSimulationData $simulation,
        public int $score,
        public GameActionScoreData $scoreBreakdown,
    ) {
    }
}
