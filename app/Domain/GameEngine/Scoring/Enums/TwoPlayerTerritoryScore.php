<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Scoring\Enums;

enum TwoPlayerTerritoryScore: int
{
    case Twelve = 12;
    case Thirteen = 13;
    case Fourteen = 14;
    case Fifteen = 15;
}
