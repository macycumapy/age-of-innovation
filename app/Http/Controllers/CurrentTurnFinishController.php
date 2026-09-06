<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\FinishActionTurnAction;
use App\Http\Requests\FinishActionTurnRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class CurrentTurnFinishController extends Controller
{
    public function __invoke(
        FinishActionTurnRequest $request,
        Game $game,
        FinishActionTurnAction $finishActionTurn,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $finishActionTurn->execute($game, $user);

        return $this->gameChanged($game);
    }
}
