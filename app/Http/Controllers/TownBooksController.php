<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ChooseTownBooksAction;
use App\Http\Requests\ChooseTownBooksRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class TownBooksController extends Controller
{
    public function __invoke(ChooseTownBooksRequest $request, Game $game, ChooseTownBooksAction $chooseBooks): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $chooseBooks->execute($game, $user, $request->disciplines());

        return to_route('games.show', $game);
    }
}
