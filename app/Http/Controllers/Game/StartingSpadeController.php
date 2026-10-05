<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Board\Actions\SpendStartingSpadeAction;
use App\Domain\GameEngine\Board\Actions\UndoStartingSpadeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\SpendStartingSpadeRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class StartingSpadeController extends Controller
{
    public function store(
        SpendStartingSpadeRequest $request,
        Game $game,
        SpendStartingSpadeAction $spendStartingSpade,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $spendStartingSpade->execute($game, $player, $request->hexId());

        return $this->gameChanged($game);
    }

    public function destroy(
        Request $request,
        Game $game,
        UndoStartingSpadeAction $undoStartingSpade,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $undoStartingSpade->execute($game, $user);

        return $this->gameChanged($game);
    }
}
