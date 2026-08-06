<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class JoinGameAction
{
    public function __construct(private CreateGamePlayerAction $createGamePlayer)
    {
    }

    public function execute(Game $game, User $user): GamePlayer
    {
        return DB::transaction(function () use ($game, $user): GamePlayer {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($lockedGame->status !== GameStatus::Lobby) {
                throw ValidationException::withMessages([
                    'game' => 'К начавшейся игре присоединиться нельзя.',
                ]);
            }

            if ($lockedGame->players()->whereBelongsTo($user)->exists()) {
                throw ValidationException::withMessages([
                    'game' => 'Вы уже присоединились к этой игре.',
                ]);
            }

            $occupiedSeats = $lockedGame->players()->pluck('seat');
            $maxPlayers = $lockedGame->state->board->variant->maxPlayers();

            if ($occupiedSeats->count() >= $maxPlayers) {
                throw ValidationException::withMessages([
                    'game' => 'В игре больше нет свободных мест.',
                ]);
            }

            $seat = collect(range(1, $maxPlayers))
                ->first(fn (int $seat): bool => ! $occupiedSeats->contains($seat));

            if ($seat === null) {
                throw ValidationException::withMessages([
                    'game' => 'Не удалось назначить свободное место.',
                ]);
            }

            return $this->createGamePlayer->execute($lockedGame, $user, (int) $seat);
        });
    }
}
