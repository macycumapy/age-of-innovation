<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\Game;
use App\Models\User;

final class GameChannel
{
    public function join(User $user, int $gameId): bool
    {
        return Game::query()
            ->availableTo($user)
            ->whereKey($gameId)
            ->exists();
    }
}
