<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\PlayerAbilities\Actions\PerformFactionAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\UseFactionActionRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class FactionActionController extends Controller
{
    public function __invoke(
        UseFactionActionRequest $request,
        Game $game,
        PerformFactionAction $performFactionAction,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $performFactionAction->execute($game, $player, $request->discipline());

        return $this->gameChanged($game);
    }
}
