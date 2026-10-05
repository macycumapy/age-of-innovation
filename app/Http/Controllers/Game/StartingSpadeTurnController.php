<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Board\Actions\FinishStartingSpadeAction;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class StartingSpadeTurnController extends Controller
{
    public function __invoke(
        Request $request,
        Game $game,
        FinishStartingSpadeAction $finishStartingSpade,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $finishStartingSpade->execute($game, $player);

        return $this->gameChanged($game);
    }
}
