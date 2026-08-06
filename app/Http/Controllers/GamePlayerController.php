<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\JoinGameAction;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;

class GamePlayerController extends Controller
{
    public function store(Request $request, Game $game, JoinGameAction $joinGame): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();

        $joinGame->execute($game, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Вы присоединились к игре.',
        ]);

        return to_route('games.show', $game);
    }
}
