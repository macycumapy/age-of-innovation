<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use Spatie\LaravelData\Data;

final class MakeInnovationResultData extends Data
{
    public function __construct(
        public int $coins,
        public int $victoryPoints,
        public int $totalBooks,
        public InnovationRewardData $reward,
    ) {
    }
}
