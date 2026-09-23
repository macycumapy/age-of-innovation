<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

final class ChooseCompetencyResultData extends Data
{
    public function __construct(
        public int $nextActiveUserId,
        public string $reason,
        public string $builtHexId,
        public int $gainedPower,
        public int $victoryPoints,
    ) {
    }
}
