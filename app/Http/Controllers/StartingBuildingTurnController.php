<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\FinishStartingBuildingTurnAction;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class StartingBuildingTurnController extends Controller
{
    public function __invoke(
        Request $request,
        Game $game,
        FinishStartingBuildingTurnAction $finishStartingBuildingTurn,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $finishStartingBuildingTurn->execute($game, $user);

        return response()->noContent();
    }
}
