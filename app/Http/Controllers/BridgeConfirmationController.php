<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ConfirmBridgeAction;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class BridgeConfirmationController extends Controller
{
    public function __invoke(Request $request, Game $game, ConfirmBridgeAction $confirmBridge): Response
    {
        /** @var User $user */
        $user = $request->user();
        $confirmBridge->execute($game, $user);

        return $this->gameChanged($game);
    }
}
