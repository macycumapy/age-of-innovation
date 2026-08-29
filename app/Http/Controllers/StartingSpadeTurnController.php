<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\FinishStartingSpadeAction;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StartingSpadeTurnController extends Controller
{
    public function __invoke(
        Request $request,
        Game $game,
        FinishStartingSpadeAction $finishStartingSpade,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $finishStartingSpade->execute($game, $user);

        return to_route('games.show', $game);
    }
}
