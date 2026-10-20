<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Enums;

use App\Domain\Game\Enums\GameStatus;
use App\Models\Game;
use App\Models\GamePlayer;

enum GameActionContinuation: string
{
    case YourTurn = 'your_turn';
    case WaitForTurn = 'wait_for_turn';
    case GameEnded = 'game_ended';

    public static function forPlayer(Game $game, GamePlayer $player): self
    {
        if (in_array($game->status, [GameStatus::Finished, GameStatus::Abandoned], true)) {
            return self::GameEnded;
        }

        $pending = $game->state->pendingInteraction;
        $isYourTurn = $pending !== null ? $pending->playerId === $player->id : $game->isActivePlayer($player);

        return $game->status === GameStatus::Active && $isYourTurn ? self::YourTurn : self::WaitForTurn;
    }
}
