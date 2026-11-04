<?php

declare(strict_types=1);

namespace App\Observers;

use App\Events\GameListChanged;
use App\Models\GamePlayer;

final class GamePlayerObserver
{
    public function created(GamePlayer $gamePlayer): void
    {
        GameListChanged::dispatch();
    }

    public function updated(GamePlayer $gamePlayer): void
    {
        if ($gamePlayer->wasChanged(['game_id', 'user_id'])) {
            GameListChanged::dispatch();
        }
    }

    public function deleted(GamePlayer $gamePlayer): void
    {
        GameListChanged::dispatch();
    }
}
