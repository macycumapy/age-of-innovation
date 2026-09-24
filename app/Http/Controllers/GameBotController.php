<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\AddGameBotAction;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Http\Requests\StoreGameBotRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;
use Inertia\Inertia;

final class GameBotController extends Controller
{
    public function __invoke(
        StoreGameBotRequest $request,
        Game $game,
        AddGameBotAction $addGameBot,
    ): Response {
        /** @var User $owner */
        $owner = $request->user();
        $difficulty = GameBotDifficulty::from($request->string('difficulty')->toString());

        $addGameBot->execute($game, $owner, $difficulty);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Бот добавлен в игру.',
        ]);

        return $this->gameChanged($game);
    }
}
