<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ConfirmPalaceGuildAction;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PalaceGuildConfirmationController extends Controller
{
    public function __invoke(Request $request, Game $game, ConfirmPalaceGuildAction $confirmPalaceGuild): Response
    {
        /** @var User $user */
        $user = $request->user();
        $confirmPalaceGuild->execute($game, $user);

        return $this->gameChanged($game);
    }
}
