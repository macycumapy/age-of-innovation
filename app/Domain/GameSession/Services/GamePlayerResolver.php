<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Services;

use App\Domain\GameSession\Data\GamePlayerContextData;
use App\Models\Game;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use LogicException;

final class GamePlayerResolver
{
    public function resolve(User $user, ?int $gameId, ?int $version = null): GamePlayerContextData
    {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('Resolve the player inside a transaction to retain the game lock.');
        }
        $players = $user->gamePlayers()
            ->when($gameId !== null, fn ($query) => $query->where('game_id', $gameId))
            ->limit(2)->get();
        if ($players->isEmpty()) {
            throw new DomainException('The user does not participate in this game or has not joined any game yet.');
        }
        if ($players->count() > 1) {
            throw new DomainException('The user participates in multiple games. Specify game_id.');
        }
        $player = $players->firstOrFail();
        $game = Game::query()->lockForUpdate()->findOrFail($player->game_id);
        if ($version !== null && $game->version !== $version) {
            throw new DomainException('Game version changed. Read state and select again.');
        }

        return new GamePlayerContextData($game, $player);
    }
}
