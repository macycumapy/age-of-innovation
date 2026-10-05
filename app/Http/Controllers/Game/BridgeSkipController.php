<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Board\Actions\SkipBridgeAction;
use App\Http\Controllers\Controller;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class BridgeSkipController extends Controller
{
    public function __invoke(Request $request, Game $game, SkipBridgeAction $skipBridge): Response
    {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $skipBridge->execute($game, $player);

        return $this->gameChanged($game);
    }
}
