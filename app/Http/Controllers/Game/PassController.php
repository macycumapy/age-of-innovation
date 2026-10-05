<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Turns\Actions\PassAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\PassRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class PassController extends Controller
{
    public function __invoke(PassRequest $request, Game $game, PassAction $pass): Response
    {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $pass->execute($game, $player, $request->knowledgeDisciplines());

        return $this->gameChanged($game);
    }
}
