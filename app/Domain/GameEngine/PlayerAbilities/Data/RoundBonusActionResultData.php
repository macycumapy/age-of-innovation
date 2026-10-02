<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\PlayerAbilities\Data;

final readonly class RoundBonusActionResultData
{
    public function __construct(
        public int $gainedPower,
        public int $victoryPoints,
    ) {
    }
}
