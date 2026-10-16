<?php

declare(strict_types=1);

namespace App\Domain\Game\Queries;

use App\Domain\Game\Data\GameStateViewData;
use App\Domain\Game\Services\GamePlayerResolver;
use App\Domain\Game\Services\StagedGameActionStore;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GetGameStateQuery
{
    public function __construct(
        private GamePlayerResolver $players,
        private StagedGameActionStore $stagedActions,
    ) {
    }

    public function execute(User $user, ?int $gameId = null): GameStateViewData
    {
        return DB::transaction(function () use ($user, $gameId): GameStateViewData {
            $context = $this->players->resolve($user, $gameId);
            $game = $context->game;

            return new GameStateViewData(
                gameId: $game->id,
                userId: $user->id,
                playerId: $context->player->id,
                version: $game->version,
                activePlayerId: $game->active_game_player_id,
                state: $game->state,
                stagedAction: $this->stagedActions->get($context->player),
            );
        });
    }
}
