<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PerformPalaceAction;
use App\Http\Requests\UsePalaceActionRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class PalaceActionController extends Controller
{
    public function __invoke(UsePalaceActionRequest $request, Game $game, PerformPalaceAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action->execute($game, $user, $request->discipline(), $request->hexId());

        return to_route('games.show', $game);
    }
}
