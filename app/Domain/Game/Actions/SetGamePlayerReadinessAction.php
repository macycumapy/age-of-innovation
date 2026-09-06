<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Events\GamePlayersChanged;
use App\Models\GamePlayer;

final class SetGamePlayerReadinessAction
{
    public function execute(GamePlayer $gamePlayer, bool $isReady): GamePlayer
    {
        $gamePlayer->update(['is_ready' => $isReady]);

        if ($gamePlayer->wasChanged('is_ready')) {
            GamePlayersChanged::dispatch($gamePlayer->game_id);
        }

        return $gamePlayer;
    }
}
