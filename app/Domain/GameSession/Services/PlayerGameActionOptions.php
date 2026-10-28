<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Services;

use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
use App\Domain\GameSession\Data\GameActionOptionsData;
use App\Models\Game;
use App\Models\GamePlayer;

final class PlayerGameActionOptions
{
    public function __construct(private GameActionOptionFinder $finder)
    {
    }

    public function get(Game $game, GamePlayer $player): GameActionOptionsData
    {
        $isActive = $game->isActivePlayer($player);

        return new GameActionOptionsData(
            version: $game->version,
            options: $isActive ? $this->finder->execute($game->state, $player->id, includeAllConversionAmounts: true) : [],
            canFinishTurn: $isActive && $game->phase->isActionPhase()
                && $game->state->round->hasTakenMainAction
                && $game->state->round->turnStartVersion !== null
                && $game->state->pendingInteraction === null,
        );
    }
}
