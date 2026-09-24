<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

final class PlaceBridgeResultData extends Data
{
    public function __construct(
        public int $nextActivePlayerId,
        public string $source,
    ) {
    }
}
