<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

final class InnovationSpecialActionResultData extends Data
{
    public function __construct(
        public int $scholars = 0,
        public int $spades = 0,
        public int $victoryPoints = 0,
    ) {
    }
}
