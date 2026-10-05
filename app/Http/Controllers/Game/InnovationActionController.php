<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Research\Actions\PerformInnovationAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\PerformInnovationActionRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class InnovationActionController extends Controller
{
    public function __invoke(
        PerformInnovationActionRequest $request,
        Game $game,
        PerformInnovationAction $performInnovationAction,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $performInnovationAction->execute($game, $player, $request->innovation());

        return $this->gameChanged($game);
    }
}
