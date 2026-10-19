<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Enums;

enum GameTurnWaitReason: string
{
    case Timeout = 'timeout';
    case YourTurn = 'your_turn';
    case GameEnded = 'game_ended';
}
