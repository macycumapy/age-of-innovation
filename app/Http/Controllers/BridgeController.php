<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\StageBridgeAction;
use App\Domain\Game\Actions\UndoBridgeAction;
use App\Http\Requests\StageBridgeRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class BridgeController extends Controller
{
    public function store(StageBridgeRequest $request, Game $game, StageBridgeAction $stageBridge): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $stageBridge->execute($game, $user, $request->fromHexId(), $request->toHexId());

        return to_route('games.show', $game);
    }

    public function destroy(Request $request, Game $game, UndoBridgeAction $undoBridge): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $undoBridge->execute($game, $user);

        return to_route('games.show', $game);
    }
}
