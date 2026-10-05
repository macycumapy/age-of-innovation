<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\PlayerAbilities\Actions\PerformPalaceAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\UsePalaceActionRequest;
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
