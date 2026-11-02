<?php

declare(strict_types=1);

namespace App\Broadcasting;

use App\Models\GamePlayer;
use App\Models\User;

final class GameChatChannel
{
    public function join(User $user, int $gameId, int $gamePlayerId): bool
    {
        return GamePlayer::query()
            ->whereKey($gamePlayerId)
            ->where('game_id', $gameId)
            ->where('user_id', $user->id)
            ->exists();
    }
}
