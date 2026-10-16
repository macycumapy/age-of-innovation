<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\GameActionOptionsData;
use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
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
            options: $isActive ? $this->finder->execute($game->state, $player->id) : [],
            canFinishTurn: $isActive && $game->phase->isActionPhase()
                && $game->state->round->hasTakenMainAction
                && $game->state->round->turnStartVersion !== null
                && $game->state->pendingInteraction === null,
        );
    }
}
