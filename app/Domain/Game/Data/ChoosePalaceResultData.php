<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

final class ChoosePalaceResultData extends Data
{
    /** @param array{steps: int, books: int, victoryPoints: int} $shippingReward */
    public function __construct(
        public int $nextActivePlayerId,
        public string $builtHexId,
        public int $victoryPoints,
        public int $gainedPower,
        public int $gainedBooks,
        public int $gainedSpades,
        public array $shippingReward,
    ) {
    }
}
