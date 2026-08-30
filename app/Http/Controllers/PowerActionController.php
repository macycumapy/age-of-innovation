<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PerformPowerActionAction;
use App\Http\Requests\UsePowerActionRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class PowerActionController extends Controller
{
    public function __invoke(
        UsePowerActionRequest $request,
        Game $game,
        PerformPowerActionAction $performPowerAction,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $performPowerAction->execute($game, $user, $request->action(), $request->sacrificeAmount());

        return to_route('games.show', $game);
    }
}
