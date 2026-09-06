<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\MakeInnovationAction;
use App\Http\Requests\MakeInnovationRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class InnovationController extends Controller
{
    public function __invoke(
        MakeInnovationRequest $request,
        Game $game,
        MakeInnovationAction $makeInnovation,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $makeInnovation->execute($game, $user, $request->innovation(), $request->bookCounts());

        return $this->gameChanged($game);
    }
}
