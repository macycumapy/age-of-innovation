<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Data;

use Spatie\LaravelData\Data;

final class SpendSpadesResultData extends Data
{
    /** @param list<string> $buildableHexIds */
    public function __construct(
        public int $nextActivePlayerId,
        public string $hexId,
        public string $terrainBefore,
        public string $terrainAfter,
        public int $spentSpades,
        public int $remainingSpades,
        public array $buildableHexIds,
        public bool $buildOffered,
        public int $bonusCoins,
        public int $victoryPoints,
    ) {
    }
}
