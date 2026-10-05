<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Economy\Actions\SacrificePowerAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\SacrificePowerRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class PowerSacrificeController extends Controller
{
    public function store(
        SacrificePowerRequest $request,
        Game $game,
        SacrificePowerAction $sacrificePower,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $sacrificePower->execute($game, $player, $request->amount());

        return $this->gameChanged($game);
    }
}
