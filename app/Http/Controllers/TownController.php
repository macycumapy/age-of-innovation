<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ChooseTownAction;
use App\Http\Requests\ChooseTownRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class TownController extends Controller
{
    public function __invoke(ChooseTownRequest $request, Game $game, ChooseTownAction $chooseTown): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $chooseTown->execute($game, $user, $request->townTile());

        return to_route('games.show', $game);
    }
}
