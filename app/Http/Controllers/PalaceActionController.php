<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PerformPalaceAction;
use App\Http\Requests\UsePalaceActionRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class PalaceActionController extends Controller
{
    public function __invoke(UsePalaceActionRequest $request, Game $game, PerformPalaceAction $action): Response
    {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $action->execute(
            $game,
            $player,
            $request->discipline(),
            $request->knowledgeDisciplines(),
            $request->hexId(),
        );

        return $this->gameChanged($game);
    }
}
