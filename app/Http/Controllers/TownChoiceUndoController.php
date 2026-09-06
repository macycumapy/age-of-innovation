<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\UndoTownChoiceAction;
use App\Http\Requests\UndoTownChoiceRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class TownChoiceUndoController extends Controller
{
    public function __invoke(
        UndoTownChoiceRequest $request,
        Game $game,
        UndoTownChoiceAction $undoTownChoice,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $undoTownChoice->execute($game, $user);

        return $this->gameChanged($game);
    }
}
