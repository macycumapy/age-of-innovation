<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ChoosePlanningBundleAction;
use App\Http\Requests\ChoosePlanningBundleRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

final class PlanningBundleController extends Controller
{
    public function store(
        ChoosePlanningBundleRequest $request,
        Game $game,
        ChoosePlanningBundleAction $choosePlanningBundle,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();

        $choosePlanningBundle->execute($game, $user, $request->homeland());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Стартовый комплект выбран.',
        ]);

        return to_route('games.show', $game);
    }
}
