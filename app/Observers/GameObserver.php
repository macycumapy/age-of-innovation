<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Game\Enums\GameStatus;
use App\Jobs\PlayAutomatedTurnJob;
use App\Models\Game;
use App\Models\GamePlayer;

final class GameObserver
{
    public function updated(Game $game): void
    {
        if (! $game->wasChanged('active_player_id')
            || $game->active_player_id === null
            || $game->status !== GameStatus::Active
            || ! $game->phase->isActionPhase()) {
            return;
        }

        $player = $game->players()
            ->where('user_id', $game->active_player_id)
            ->first();

        if ($player instanceof GamePlayer && $player->bot_difficulty !== null) {
            PlayAutomatedTurnJob::dispatch($game->id, $game->active_player_id);
        }
    }
}
