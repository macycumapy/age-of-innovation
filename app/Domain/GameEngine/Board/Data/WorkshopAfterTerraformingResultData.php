<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Data;

use Spatie\LaravelData\Data;

final class WorkshopAfterTerraformingResultData extends Data
{
    /** @param list<array{source: string, id: string, points: int}> $scoringSources */
    public function __construct(
        public int $nextActivePlayerId,
        public int $victoryPoints,
        public int $bonusCoins,
        public array $scoringSources,
        public bool $felineBonusPending,
        public int $toolCost,
        public int $coinCost,
    ) {
    }
}
