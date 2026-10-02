<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use Spatie\LaravelData\Data;

final class InnovationRewardData extends Data
{
    public function __construct(
        public int $victoryPoints,
        public int $scholars,
        public int $power,
        public int $books,
        public int $developmentTrackBooks,
        public int $knowledgeSteps,
        public int $shippingSteps,
        public int $terraformingSteps,
        public int $gainedPower,
    ) {
    }
}
