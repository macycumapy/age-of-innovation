<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum TwoPlayerTerritoryScore: int
{
    case Twelve = 12;
    case Thirteen = 13;
    case Fourteen = 14;
    case Fifteen = 15;
}
