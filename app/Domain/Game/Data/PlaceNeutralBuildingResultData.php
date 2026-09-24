<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

final class PlaceNeutralBuildingResultData extends Data
{
    /** @param list<array{source: string, id: string, points: int}> $scoringSources */
    public function __construct(
        public int $nextActivePlayerId,
        public int $toolCost,
        public int $victoryPoints,
        public int $bonusCoins,
        public array $scoringSources,
    ) {
    }
}
