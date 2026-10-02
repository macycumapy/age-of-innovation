<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Data;

use Spatie\LaravelData\Data;

final class PalaceWaterTownResultData extends Data
{
    /**
     * @param list<string> $townHexIds
     * @param list<string> $queuedBuiltHexIds
     */
    public function __construct(
        public int $nextActivePlayerId,
        public string $builtHexId,
        public array $townHexIds,
        public array $queuedBuiltHexIds,
    ) {
    }
}
