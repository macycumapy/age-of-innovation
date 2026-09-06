<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\StageBridgeAction;
use App\Domain\Game\Actions\UndoBridgeAction;
use App\Http\Requests\StageBridgeRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class BridgeController extends Controller
{
    public function store(StageBridgeRequest $request, Game $game, StageBridgeAction $stageBridge): Response
    {
        /** @var User $user */
        $user = $request->user();
        $stageBridge->execute($game, $user, $request->fromHexId(), $request->toHexId());

        return $this->gameChanged($game);
    }

    public function destroy(Request $request, Game $game, UndoBridgeAction $undoBridge): Response
    {
        /** @var User $user */
        $user = $request->user();
        $undoBridge->execute($game, $user);

        return $this->gameChanged($game);
    }
}
