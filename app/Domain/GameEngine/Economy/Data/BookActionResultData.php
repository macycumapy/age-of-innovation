<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Economy\Data;

use Spatie\LaravelData\Data;

final class BookActionResultData extends Data
{
    public function __construct(
        public int $nextActivePlayerId,
        public int $victoryPoints = 0,
        public int $buildingBonusPoints = 0,
        public int $buildingBonusCoins = 0,
        public int $gainedPower = 0,
    ) {
    }
}
