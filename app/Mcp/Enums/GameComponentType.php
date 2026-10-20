<?php

declare(strict_types=1);

namespace App\Mcp\Enums;

enum GameComponentType: string
{
    case Faction = 'faction';
    case Competency = 'competency';
    case Innovation = 'innovation';
    case Palace = 'palace';
    case RoundBonus = 'round_bonus';
    case TownTile = 'town_tile';
}
