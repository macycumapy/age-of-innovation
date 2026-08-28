<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ChooseStartingCompetencyAction;
use App\Http\Requests\ChooseStartingCompetencyRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class StartingCompetencyController extends Controller
{
    public function store(
        ChooseStartingCompetencyRequest $request,
        Game $game,
        ChooseStartingCompetencyAction $chooseStartingCompetency,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $chooseStartingCompetency->execute($game, $user, $request->competency());

        return to_route('games.show', $game);
    }
}
