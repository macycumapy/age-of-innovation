<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Interactions\Data;

use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Enums\GameActionAvailabilityReason;

final readonly class GameActionAvailabilityData
{
    /** @param list<GameActionAvailabilityReason> $unavailableReasons */
    public function __construct(
        public GameActionOptionType $type,
        public int $availableOptionCount,
        public array $unavailableReasons,
    ) {
    }
}
