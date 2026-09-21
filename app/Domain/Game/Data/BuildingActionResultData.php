<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\BuildingType;
use Spatie\LaravelData\Data;

final class BuildingActionResultData extends Data
{
    /** @param list<array{source: string, id: string, points: int}> $scoringSources */
    public function __construct(
        public int $nextActiveUserId,
        public ?BuildingType $source,
        public BuildingType $target,
        public int $tools,
        public int $coins,
        public int $victoryPoints,
        public int $bonusCoins,
        public array $scoringSources,
    ) {
    }
}
