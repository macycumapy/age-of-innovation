<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Services;

use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;

final class BridgeSupply
{
    private const int SUPPLY_LIMIT = 3;

    public function remaining(GameStateData $state, GamePlayerStateData $player): int
    {
        $builtBridgeCount = collect($state->board->bridges)
            ->where('ownerPlayerId', $player->playerId)
            ->count();

        return max(0, self::SUPPLY_LIMIT - $builtBridgeCount);
    }
}
