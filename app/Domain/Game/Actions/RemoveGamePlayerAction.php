<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameStatus;
use App\Events\GamePlayersChanged;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RemoveGamePlayerAction
{
    public function execute(Game $game, GamePlayer $gamePlayer, User $user): void
    {
        DB::transaction(function () use ($game, $gamePlayer, $user): void {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($lockedGame->status !== GameStatus::Lobby) {
                throw ValidationException::withMessages([
                    'game' => 'Покинуть начавшуюся игру нельзя.',
                ]);
            }

            $lockedPlayer = $lockedGame->players()
                ->whereKey($gamePlayer->id)
                ->lockForUpdate()
                ->firstOrFail();
            $wasOwner = $lockedPlayer->seat === 1;
            $isOwner = $lockedGame->players()
                ->where('seat', 1)
                ->where('user_id', $user->id)
                ->exists();

            if ($lockedPlayer->user_id !== $user->id && ! $isOwner) {
                throw new AuthorizationException('Исключать игроков может только владелец игры.');
            }

            $lockedPlayer->delete();

            if (! $lockedGame->players()->exists()) {
                $lockedGame->delete();
            } elseif ($wasOwner) {
                $lockedGame->players()->orderBy('seat')->firstOrFail()->update(['seat' => 1]);
            }

            GamePlayersChanged::dispatch($lockedGame->id);
        });
    }
}
