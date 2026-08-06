<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ChooseStartingResourcesAction;
use App\Http\Requests\ChooseStartingResourcesRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class StartingResourcesController extends Controller
{
    public function store(
        ChooseStartingResourcesRequest $request,
        Game $game,
        ChooseStartingResourcesAction $chooseStartingResources,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $chooseStartingResources->execute(
            $game,
            $user,
            $request->bookDiscipline(),
            $request->knowledgeDisciplines(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Стартовые ресурсы распределены.',
        ]);

        return to_route('games.show', $game);
    }
}
