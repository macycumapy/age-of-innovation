<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Data;

use App\Domain\GameEngine\State\Data\GamePlayerStateData;
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
