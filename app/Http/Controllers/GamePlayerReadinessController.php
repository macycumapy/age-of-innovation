<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\SetGamePlayerReadinessAction;
use App\Http\Requests\UpdateGamePlayerReadinessRequest;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Http\Response;
use Inertia\Inertia;

class GamePlayerReadinessController extends Controller
{
    public function update(
        UpdateGamePlayerReadinessRequest $request,
        Game $game,
        GamePlayer $gamePlayer,
        SetGamePlayerReadinessAction $setReadiness,
    ): Response {
        $setReadiness->execute($gamePlayer, $request->isReady());

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $request->isReady() ? 'Готовность подтверждена.' : 'Готовность отменена.',
        ]);

        return $this->gameChanged($game);
    }
}
