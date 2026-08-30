<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\FinishActionTurnAction;
use App\Http\Requests\FinishActionTurnRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class CurrentTurnFinishController extends Controller
{
    public function __invoke(
        FinishActionTurnRequest $request,
        Game $game,
        FinishActionTurnAction $finishActionTurn,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $finishActionTurn->execute($game, $user);

        return to_route('games.show', $game);
    }
}
