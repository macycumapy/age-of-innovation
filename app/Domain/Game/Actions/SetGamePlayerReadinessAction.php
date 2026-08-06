<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Models\GamePlayer;

final class SetGamePlayerReadinessAction
{
    public function execute(GamePlayer $gamePlayer, bool $isReady): GamePlayer
    {
        $gamePlayer->update(['is_ready' => $isReady]);

        return $gamePlayer;
    }
}
