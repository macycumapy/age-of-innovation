<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\History\Actions\UndoLastGameAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\UndoLastGameActionRequest;
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
