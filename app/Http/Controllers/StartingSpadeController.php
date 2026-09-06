<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\SpendStartingSpadeAction;
use App\Domain\Game\Actions\UndoStartingSpadeAction;
use App\Http\Requests\SpendStartingSpadeRequest;
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
        $spendStartingSpade->execute($game, $user, $request->hexId());

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
