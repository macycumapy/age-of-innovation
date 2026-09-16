<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

final readonly class TownRewardResultData
{
    public function __construct(
        public int $victoryPoints,
        public int $gainedPower,
    ) {
    }
}
