<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\GameActionAvailabilityReason;
use App\Domain\Game\Enums\GameActionOptionType;

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
