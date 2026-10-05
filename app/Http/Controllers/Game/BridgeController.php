<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Board\Actions\StageBridgeAction;
use App\Domain\GameEngine\Board\Actions\UndoBridgeAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\StageBridgeRequest;
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
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $stageBridge->execute($game, $player, $request->fromHexId(), $request->toHexId());

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
