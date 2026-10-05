<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Setup\Actions\FinishStartingBuildingTurnAction;
use App\Http\Controllers\Controller;
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
        $player = $game->players()->whereBelongsTo($user)->sole();
        $finishStartingBuildingTurn->execute($game, $player);

        return $this->gameChanged($game);
    }
}
