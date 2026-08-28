<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\FinishStartingBuildingTurnAction;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StartingBuildingTurnController extends Controller
{
    public function __invoke(
        Request $request,
        Game $game,
        FinishStartingBuildingTurnAction $finishStartingBuildingTurn,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $finishStartingBuildingTurn->execute($game, $user);

        return to_route('games.show', $game);
    }
}
