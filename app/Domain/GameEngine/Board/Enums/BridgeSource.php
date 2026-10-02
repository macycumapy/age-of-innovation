<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Enums;

enum BridgeSource: string
{
    case Power = 'power';
    case RoundBonus = 'round_bonus';
    case Faction = 'faction';
    case Palace15 = 'palace_15';
}
