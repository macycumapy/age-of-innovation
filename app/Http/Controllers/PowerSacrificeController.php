<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\SacrificePowerAction;
use App\Http\Requests\SacrificePowerRequest;
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
        $sacrificePower->execute($game, $user, $request->amount());

        return $this->gameChanged($game);
    }
}
