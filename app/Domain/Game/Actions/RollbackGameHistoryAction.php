<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Events\GameHistoryChanged;
use App\Models\Game;
use App\Models\GameAction;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RollbackGameHistoryAction
{
    public function __construct(private ReplayGameHistoryAction $replayGameHistory)
    {
    }

    public function execute(Game $game, GameAction $checkpoint): Game
    {
        return DB::transaction(function () use ($game, $checkpoint): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $lockedCheckpoint = $lockedGame->actions()
                ->lockForUpdate()
                ->findOrFail($checkpoint->id);

            if ($lockedCheckpoint->type !== GameActionType::PhaseCheckpoint) {
                throw ValidationException::withMessages([
                    'history' => 'Откат возможен только к началу фазы.',
                ]);
            }

            /** @var Collection<int, GameAction> $actions */
            $actions = $lockedGame->actions()
                ->lockForUpdate()
                ->where('sequence', '<=', $lockedCheckpoint->sequence)
                ->orderBy('sequence')
                ->get();

            $this->replayGameHistory->execute($lockedGame, $actions);
            $lockedGame->actions()
                ->where('sequence', '>', $lockedCheckpoint->sequence)
                ->delete();
            GameHistoryChanged::dispatch($lockedGame->id);

            return $lockedGame->refresh();
        });
    }
}
