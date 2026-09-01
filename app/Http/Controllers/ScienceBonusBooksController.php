<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ChooseScienceBonusBooksAction;
use App\Http\Requests\ChooseScienceBonusBooksRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class ScienceBonusBooksController extends Controller
{
    public function __invoke(
        ChooseScienceBonusBooksRequest $request,
        Game $game,
        ChooseScienceBonusBooksAction $action,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $action->execute($game, $user, $request->disciplines());

        return to_route('games.show', $game);
    }
}
