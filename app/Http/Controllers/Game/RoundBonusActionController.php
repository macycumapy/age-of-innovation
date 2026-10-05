<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\PlayerAbilities\Actions\PerformRoundBonusAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\UseRoundBonusActionRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class RoundBonusActionController extends Controller
{
    public function __invoke(
        UseRoundBonusActionRequest $request,
        Game $game,
        PerformRoundBonusAction $performRoundBonusAction,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $performRoundBonusAction->execute($game, $player, $request->discipline());

        return $this->gameChanged($game);
    }
}
