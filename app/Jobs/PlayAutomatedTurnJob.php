<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Game\Actions\PlayAutomatedTurnAction;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PlayAutomatedTurnJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [1, 5, 15];

    public function __construct(
        public readonly int $gameId,
        public readonly int $userId,
    ) {
        $this->afterCommit();
    }

    public function handle(PlayAutomatedTurnAction $playAutomatedTurn): void
    {
        $game = Game::query()->find($this->gameId);

        if (! $game instanceof Game || $game->active_player_id !== $this->userId) {
            return;
        }

        $player = $game->players()
            ->where('user_id', $this->userId)
            ->first();

        if (! $player instanceof GamePlayer || $player->bot_difficulty === null) {
            return;
        }

        $playAutomatedTurn->execute($game, $player->user()->firstOrFail(), $player->bot_difficulty);
    }

    public function uniqueId(): string
    {
        return "{$this->gameId}:{$this->userId}";
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Не удалось выполнить ход автоматического игрока.', [
            'game_id' => $this->gameId,
            'user_id' => $this->userId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
