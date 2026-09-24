<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

final class ChooseCompetencyResultData extends Data
{
    public function __construct(
        public int $nextActivePlayerId,
        public string $reason,
        public string $builtHexId,
        public int $gainedPower,
        public int $victoryPoints,
    ) {
    }
}
