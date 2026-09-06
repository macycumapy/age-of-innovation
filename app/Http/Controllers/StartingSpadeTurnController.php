<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\FinishStartingSpadeAction;
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
        $finishStartingSpade->execute($game, $user);

        return $this->gameChanged($game);
    }
}
