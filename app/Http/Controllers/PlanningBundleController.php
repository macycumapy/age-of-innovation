<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\GameEngine\Setup\Actions\ChoosePlanningBundleAction;
use App\Http\Requests\ChoosePlanningBundleRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;
use Inertia\Inertia;

final class PlanningBundleController extends Controller
{
    public function store(
        ChoosePlanningBundleRequest $request,
        Game $game,
        ChoosePlanningBundleAction $choosePlanningBundle,
    ): Response {
        /** @var User $user */
        $user = $request->user();

        $player = $game->players()->whereBelongsTo($user)->sole();
        $choosePlanningBundle->execute($game, $player, $request->homeland());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Стартовый комплект выбран.',
        ]);

        return $this->gameChanged($game);
    }
}
