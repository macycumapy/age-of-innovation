<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Settings\Services\SettingsService;
use App\Events\GamePlayersChanged;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class AddGameBotAction
{
    public function __construct(private CreateGamePlayerAction $createGamePlayer, private SettingsService $settings)
    {
    }

    public function execute(Game $game, User $owner, GameBotDifficulty $difficulty): GamePlayer
    {
        return DB::transaction(function () use ($game, $owner, $difficulty): GamePlayer {
            $settings = $this->settings->get();

            if (! $settings->botsEnabled) {
                throw ValidationException::withMessages(['game' => 'Добавление ботов отключено.']);
            }

            if (! in_array($difficulty, $settings->botDifficulties, true)) {
                throw ValidationException::withMessages(['difficulty' => 'Эта сложность бота недоступна.']);
            }

            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            if ($lockedGame->status !== GameStatus::Lobby) {
                throw ValidationException::withMessages(['game' => 'Добавлять ботов можно только до начала игры.']);
            }

            if (! $lockedGame->players()->where('seat', 1)->whereBelongsTo($owner)->exists()) {
                throw new AuthorizationException('Добавлять ботов может только владелец игры.');
            }

            $occupiedSeats = $lockedGame->players()->pluck('seat');
            $maxPlayers = $lockedGame->state->board->variant->maxPlayers();
            $seat = collect(range(1, $maxPlayers))
                ->first(fn (int $candidate): bool => ! $occupiedSeats->contains($candidate));

            if ($seat === null) {
                throw ValidationException::withMessages(['game' => 'В игре больше нет свободных мест.']);
            }

            $gamePlayer = $this->createGamePlayer->execute($lockedGame, null, $seat, $difficulty);

            GamePlayersChanged::dispatch($lockedGame->id);

            return $gamePlayer;
        });
    }
}
