<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PerformRoundBonusAction;
use App\Http\Requests\UseRoundBonusActionRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class RoundBonusActionController extends Controller
{
    public function __invoke(
        UseRoundBonusActionRequest $request,
        Game $game,
        PerformRoundBonusAction $performRoundBonusAction,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $performRoundBonusAction->execute($game, $user, $request->discipline());

        return to_route('games.show', $game);
    }
}
