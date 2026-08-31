<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\SendScholarAction;
use App\Http\Requests\SendScholarRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class ScholarController extends Controller
{
    public function __invoke(SendScholarRequest $request, Game $game, SendScholarAction $sendScholar): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $sendScholar->execute($game, $user, $request->discipline(), $request->boolean('place'));

        return to_route('games.show', $game);
    }
}
