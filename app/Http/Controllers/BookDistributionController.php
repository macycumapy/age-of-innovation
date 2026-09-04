<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ChooseBooksAction;
use App\Http\Requests\ChooseBooksRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class BookDistributionController extends Controller
{
    public function __invoke(
        ChooseBooksRequest $request,
        Game $game,
        ChooseBooksAction $chooseBooks,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $chooseBooks->execute($game, $user, $request->bookCounts());

        return to_route('games.show', $game);
    }
}
