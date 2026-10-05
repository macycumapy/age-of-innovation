<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Towns\Actions\ChooseTownAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\ChooseTownRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class TownController extends Controller
{
    public function __invoke(ChooseTownRequest $request, Game $game, ChooseTownAction $chooseTown): Response
    {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $chooseTown->execute($game, $player, $request->townTile());

        return $this->gameChanged($game);
    }
}
