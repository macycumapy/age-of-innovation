<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PerformBookActionAction;
use App\Http\Requests\UseBookActionRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class BookActionController extends Controller
{
    public function __invoke(
        UseBookActionRequest $request,
        Game $game,
        PerformBookActionAction $performBookAction,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $performBookAction->execute(
            $game,
            $user,
            $request->action(),
            $request->bookCounts(),
            $request->discipline(),
            $request->hexId(),
        );

        return to_route('games.show', $game);
    }
}
