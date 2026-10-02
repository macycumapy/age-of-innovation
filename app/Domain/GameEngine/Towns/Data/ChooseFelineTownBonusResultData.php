<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Towns\Data;

use Spatie\LaravelData\Data;

final class ChooseFelineTownBonusResultData extends Data
{
    public function __construct(
        public int $nextActivePlayerId,
        public int $victoryPoints,
        public int $gainedPower,
        public ?string $continueBuildingHexId,
    ) {
    }
}
