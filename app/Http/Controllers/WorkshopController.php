<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\BuildWorkshopAction;
use App\Http\Requests\BuildWorkshopRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class WorkshopController extends Controller
{
    public function __invoke(BuildWorkshopRequest $request, Game $game, BuildWorkshopAction $buildWorkshop): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $buildWorkshop->execute($game, $user, $request->hexId());

        return to_route('games.show', $game);
    }
}
