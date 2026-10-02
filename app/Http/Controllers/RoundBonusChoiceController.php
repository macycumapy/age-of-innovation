<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\GameEngine\Turns\Actions\ChooseRoundBonusAction;
use App\Http\Requests\ChooseRoundBonusRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class RoundBonusChoiceController extends Controller
{
    public function __invoke(ChooseRoundBonusRequest $request, Game $game, ChooseRoundBonusAction $choose): Response
    {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $choose->execute($game, $player, $request->roundBonus());

        return $this->gameChanged($game);
    }
}
