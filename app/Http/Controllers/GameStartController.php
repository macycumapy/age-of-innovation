<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\StartGameAction;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

final class GameStartController extends Controller
{
    public function __invoke(Request $request, Game $game, StartGameAction $startGame): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $startGame->execute($game, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Игра началась.',
        ]);

        return to_route('games.show', $game);
    }
}
