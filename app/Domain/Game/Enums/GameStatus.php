<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum GameStatus: string
{
    case Lobby = 'lobby';
    case Active = 'active';
    case Finished = 'finished';
    case Abandoned = 'abandoned';
}
