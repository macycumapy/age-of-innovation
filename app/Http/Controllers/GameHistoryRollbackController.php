<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\RollbackGameHistoryAction;
use App\Http\Requests\RollbackGameHistoryRequest;
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
