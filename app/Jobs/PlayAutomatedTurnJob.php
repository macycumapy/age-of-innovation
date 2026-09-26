<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Domain\Game\Actions\PlayAutomatedTurnAction;
use App\Events\GameChanged;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Contracts\Queue\ShouldBeUniqueUntilProcessing;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

final class PlayAutomatedTurnJob implements ShouldBeUniqueUntilProcessing, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public int $timeout = 60;

    public int $uniqueFor = 300;

    /** @var list<int> */
    public array $backoff = [1, 5, 15];

    public function __construct(
        public readonly int $gameId,
        public readonly int $gamePlayerId,
    ) {
        $this->afterCommit();
    }

    public function handle(PlayAutomatedTurnAction $playAutomatedTurn): void
    {
        $game = Game::query()->find($this->gameId);

        if (! $game instanceof Game || $game->active_game_player_id !== $this->gamePlayerId) {
            return;
        }

        $player = $game->players()->find($this->gamePlayerId);

        if (! $player instanceof GamePlayer || $player->bot_difficulty === null) {
            return;
        }

        $playAutomatedTurn->execute($game, $player, $player->bot_difficulty, singleDecision: true);
        GameChanged::dispatch($game->id);

        $game->refresh();
        if ($game->active_game_player_id === $player->id) {
            self::dispatch($game->id, $player->id);
        }
    }

    public function uniqueId(): string
    {
        return "{$this->gameId}:{$this->gamePlayerId}";
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Не удалось выполнить ход автоматического игрока.', [
            'game_id' => $this->gameId,
            'game_player_id' => $this->gamePlayerId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
