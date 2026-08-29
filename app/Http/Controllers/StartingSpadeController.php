<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\SpendStartingSpadeAction;
use App\Domain\Game\Actions\UndoStartingSpadeAction;
use App\Http\Requests\SpendStartingSpadeRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StartingSpadeController extends Controller
{
    public function store(
        SpendStartingSpadeRequest $request,
        Game $game,
        SpendStartingSpadeAction $spendStartingSpade,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $spendStartingSpade->execute($game, $user, $request->hexId());

        return to_route('games.show', $game);
    }

    public function destroy(
        Request $request,
        Game $game,
        UndoStartingSpadeAction $undoStartingSpade,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $undoStartingSpade->execute($game, $user);

        return to_route('games.show', $game);
    }
}
