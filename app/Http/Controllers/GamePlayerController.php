<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\JoinGameAction;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Inertia\Inertia;

class GamePlayerController extends Controller
{
    public function store(Request $request, Game $game, JoinGameAction $joinGame): Response
    {
        /** @var User $user */
        $user = $request->user();

        $joinGame->execute($game, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Вы присоединились к игре.',
        ]);

        return $this->gameChanged($game);
    }
}
