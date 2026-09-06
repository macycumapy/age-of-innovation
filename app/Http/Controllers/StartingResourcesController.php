<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ChooseStartingResourcesAction;
use App\Http\Requests\ChooseStartingResourcesRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;
use Inertia\Inertia;

final class StartingResourcesController extends Controller
{
    public function store(
        ChooseStartingResourcesRequest $request,
        Game $game,
        ChooseStartingResourcesAction $chooseStartingResources,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $chooseStartingResources->execute(
            $game,
            $user,
            $request->bookDisciplines(),
            $request->knowledgeDisciplines(),
            $request->competency(),
        );

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Стартовые ресурсы распределены.',
        ]);

        return $this->gameChanged($game);
    }
}
