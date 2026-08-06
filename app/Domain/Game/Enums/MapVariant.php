<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum MapVariant: string
{
    case OneToThreePlayers = 'one_to_three_players';
    case ThreeToFivePlayers = 'three_to_five_players';

    public function maxPlayers(): int
    {
        return match ($this) {
            self::OneToThreePlayers => 3,
            self::ThreeToFivePlayers => 5,
        };
    }
}
