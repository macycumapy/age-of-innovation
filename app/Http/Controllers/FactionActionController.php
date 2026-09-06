<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PerformFactionAction;
use App\Http\Requests\UseFactionActionRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class FactionActionController extends Controller
{
    public function __invoke(
        UseFactionActionRequest $request,
        Game $game,
        PerformFactionAction $performFactionAction,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $performFactionAction->execute($game, $user, $request->discipline());

        return $this->gameChanged($game);
    }
}
