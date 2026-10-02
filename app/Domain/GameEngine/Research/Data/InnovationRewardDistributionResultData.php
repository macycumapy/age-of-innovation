<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use Spatie\LaravelData\Data;

final class InnovationRewardDistributionResultData extends Data
{
    public function __construct(
        public int $nextActivePlayerId,
        public int $victoryPoints,
        public int $gainedPower,
    ) {
    }
}
