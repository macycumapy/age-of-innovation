<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;

final class CreateGamePlayerAction
{
    public function execute(Game $game, User $user, int $seat = 1): GamePlayer
    {
        return $game->players()->create([
            'user_id' => $user->id,
            'seat' => $seat,
        ]);
    }
}
