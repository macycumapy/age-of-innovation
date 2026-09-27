<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\GameActionOptionType;

final readonly class GameActionCandidateDiagnosticsData
{
    public function __construct(
        public GameActionOptionType $type,
        public int $score,
        public int $rank,
        public int $scoreDelta,
        public bool $selected,
    ) {
    }
}
