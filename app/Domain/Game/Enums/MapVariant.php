<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum MapVariant: string
{
    case OneToThreePlayers = 'one_to_three_players';
    case ThreeToFivePlayers = 'three_to_five_players';
}
