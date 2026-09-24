<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

final class ChooseTownResultData extends Data
{
    /** @param list<string> $townHexIds */
    public function __construct(
        public int $nextActivePlayerId,
        public ?string $townId,
        public array $townHexIds,
        public string $markerHexId,
        public int $victoryPoints,
        public int $gainedPower,
    ) {
    }
}
