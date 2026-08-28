<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\UndoLastGameAction;
use App\Http\Requests\UndoLastGameActionRequest;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;

final class GameHistoryUndoController extends Controller
{
    public function __invoke(
        UndoLastGameActionRequest $request,
        Game $game,
        UndoLastGameAction $undoLastGameAction,
    ): RedirectResponse {
        $undoLastGameAction->execute($game);

        return to_route('games.show', $game);
    }
}
