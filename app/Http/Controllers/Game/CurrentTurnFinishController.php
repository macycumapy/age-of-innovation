<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Turns\Actions\FinishActionTurnAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\FinishActionTurnRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class CurrentTurnFinishController extends Controller
{
    public function __invoke(
        FinishActionTurnRequest $request,
        Game $game,
        FinishActionTurnAction $finishActionTurn,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $finishActionTurn->execute($game, $player);

        return $this->gameChanged($game);
    }
}
