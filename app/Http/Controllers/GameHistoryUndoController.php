<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\UndoLastGameAction;
use App\Http\Requests\UndoLastGameActionRequest;
use App\Models\Game;
use Illuminate\Http\Response;

final class GameHistoryUndoController extends Controller
{
    public function __invoke(
        UndoLastGameActionRequest $request,
        Game $game,
        UndoLastGameAction $undoLastGameAction,
    ): Response {
        $undoLastGameAction->execute($game);

        return $this->gameChanged($game);
    }
}
