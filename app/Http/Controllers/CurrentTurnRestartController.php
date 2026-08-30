<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\RestartCurrentTurnAction;
use App\Http\Requests\RestartCurrentTurnRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class CurrentTurnRestartController extends Controller
{
    public function __invoke(
        RestartCurrentTurnRequest $request,
        Game $game,
        RestartCurrentTurnAction $restartCurrentTurn,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $restartCurrentTurn->execute($game, $user);

        return to_route('games.show', $game);
    }
}
