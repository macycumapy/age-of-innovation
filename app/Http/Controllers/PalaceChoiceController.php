<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\GameEngine\PlayerAbilities\Actions\ChoosePalaceAction;
use App\Http\Requests\ChoosePalaceRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class PalaceChoiceController extends Controller
{
    public function __invoke(
        ChoosePalaceRequest $request,
        Game $game,
        ChoosePalaceAction $choosePalace,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $choosePalace->execute($game, $player, $request->palace());

        return $this->gameChanged($game);
    }
}
