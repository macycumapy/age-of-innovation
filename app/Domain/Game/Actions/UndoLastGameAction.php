<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Models\Game;
use App\Models\GameAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UndoLastGameAction
{
    public function __construct(private ReplayGameHistoryAction $replayGameHistory)
    {
    }

    public function execute(Game $game): Game
    {
        return DB::transaction(function () use ($game): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            /** @var Collection<int, GameAction> $actions */
            $actions = $lockedGame->actions()
                ->lockForUpdate()
                ->orderBy('sequence')
                ->get();
            $lastAction = $actions->pop();

            if (! $lastAction instanceof GameAction) {
                throw ValidationException::withMessages([
                    'history' => 'В истории нет действия для отката.',
                ]);
            }

            $this->replayGameHistory->execute($lockedGame, $actions);
            $lastAction->delete();

            return $lockedGame->refresh();
        });
    }
}
