<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ConfirmBridgeAction;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BridgeConfirmationController extends Controller
{
    public function __invoke(Request $request, Game $game, ConfirmBridgeAction $confirmBridge): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $confirmBridge->execute($game, $user);

        return to_route('games.show', $game);
    }
}
