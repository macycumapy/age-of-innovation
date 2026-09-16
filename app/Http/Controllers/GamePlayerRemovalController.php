<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\RemoveGamePlayerAction;
use App\Http\Requests\RemoveGamePlayerRequest;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Http\Response;
use Inertia\Inertia;

final class GamePlayerRemovalController extends Controller
{
    public function __invoke(
        RemoveGamePlayerRequest $request,
        Game $game,
        GamePlayer $gamePlayer,
        RemoveGamePlayerAction $removeGamePlayer,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $isLeaving = $gamePlayer->user_id === $user->id;

        $removeGamePlayer->execute($game, $gamePlayer, $user);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $isLeaving ? 'Вы покинули игру.' : 'Игрок исключён из игры.',
        ]);

        return $this->gameChanged($game);
    }
}
