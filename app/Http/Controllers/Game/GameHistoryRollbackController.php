<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\History\Actions\RollbackGameHistoryAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\RollbackGameHistoryRequest;
use App\Models\Game;
use App\Models\GameAction;
use Illuminate\Http\Response;

final class GameHistoryRollbackController extends Controller
{
    public function __invoke(
        RollbackGameHistoryRequest $request,
        Game $game,
        GameAction $action,
        RollbackGameHistoryAction $rollbackGameHistory,
    ): Response {
        $rollbackGameHistory->execute($game, $action);

        return $this->gameChanged($game);
    }
}
