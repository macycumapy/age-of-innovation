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

            $actionsToDelete = collect([$lastAction]);

            if ($lastAction->type === GameActionType::PhaseCheckpoint) {
                $phaseStartingAction = $actions->pop();

                while ($phaseStartingAction instanceof GameAction && $this->isSystemPhaseAction($phaseStartingAction)) {
                    $actionsToDelete->push($phaseStartingAction);
                    $phaseStartingAction = $actions->pop();
                }

                if (! $phaseStartingAction instanceof GameAction) {
                    throw ValidationException::withMessages([
                        'history' => 'Перед чекпоинтом фазы отсутствует действие перехода.',
                    ]);
                }

                $actionsToDelete->push($phaseStartingAction);
            } elseif ($this->isSystemPhaseAction($lastAction)) {
                do {
                    $phaseStartingAction = $actions->pop();

                    if (! $phaseStartingAction instanceof GameAction) {
                        throw ValidationException::withMessages([
                            'history' => 'Перед системной фазой отсутствует действие перехода.',
                        ]);
                    }

                    $actionsToDelete->push($phaseStartingAction);
                } while ($this->isSystemPhaseAction($phaseStartingAction));
            }

            $this->replayGameHistory->execute($lockedGame, $actions);
            GameAction::query()->whereKey($actionsToDelete->pluck('id'))->delete();
            GameHistoryChanged::dispatch($lockedGame->id);

            return $lockedGame->refresh();
        });
    }

    private function isSystemPhaseAction(GameAction $action): bool
    {
        return in_array($action->type, [GameActionType::IncomePhase, GameActionType::ScienceBonusPhase], true);
    }
}
