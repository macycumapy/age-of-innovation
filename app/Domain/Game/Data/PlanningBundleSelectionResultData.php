<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

final class PlanningBundleSelectionResultData extends Data
{
    public function __construct(
        public GamePlayerStateData $player,
        public PlanningBundleData $bundle,
        public int $gainedPower,
        public int $nextActivePlayerId,
    ) {
    }
}
