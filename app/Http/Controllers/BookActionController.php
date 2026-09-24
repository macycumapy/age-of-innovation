<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PerformBookActionAction;
use App\Http\Requests\UseBookActionRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class BookActionController extends Controller
{
    public function __invoke(
        UseBookActionRequest $request,
        Game $game,
        PerformBookActionAction $performBookAction,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $performBookAction->execute(
            $game,
            $player,
            $request->action(),
            $request->bookCounts(),
            $request->discipline(),
            $request->hexId(),
        );

        return $this->gameChanged($game);
    }
}
