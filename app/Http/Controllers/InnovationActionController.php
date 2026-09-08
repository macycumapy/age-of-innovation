<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PerformInnovationAction;
use App\Http\Requests\PerformInnovationActionRequest;
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
        $performInnovationAction->execute($game, $user, $request->innovation());

        return $this->gameChanged($game);
    }
}
