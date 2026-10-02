<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Data;

use Spatie\LaravelData\Data;

final class PlacePalaceGuildResultData extends Data
{
    /**
     * @param array{victoryPoints: int, coins: int, sources: list<array{source: string, id: string, points: int}>} $bonuses
     */
    public function __construct(
        public int $nextActivePlayerId,
        public string $palaceBuiltHexId,
        public array $bonuses,
    ) {
    }
}
