<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\GameEngine\PlayerAbilities\Actions\PerformCompetencyAction;
use App\Http\Requests\UseCompetencyActionRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class CompetencyActionController extends Controller
{
    public function __invoke(
        UseCompetencyActionRequest $request,
        Game $game,
        PerformCompetencyAction $performCompetencyAction,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $performCompetencyAction->execute($game, $player);

        return $this->gameChanged($game);
    }
}
