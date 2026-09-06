<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PerformCompetencyAction;
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
        $performCompetencyAction->execute($game, $user);

        return $this->gameChanged($game);
    }
}
