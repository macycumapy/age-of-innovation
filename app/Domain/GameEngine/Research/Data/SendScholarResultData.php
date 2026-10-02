<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use Spatie\LaravelData\Data;

final class SendScholarResultData extends Data
{
    public function __construct(
        public ?int $slotIndex,
        public int $advancedSteps,
        public int $victoryPoints,
        public int $gainedPower,
    ) {
    }
}
