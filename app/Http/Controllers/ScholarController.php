<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\GameEngine\Research\Actions\SendScholarAction;
use App\Http\Requests\SendScholarRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class ScholarController extends Controller
{
    public function __invoke(SendScholarRequest $request, Game $game, SendScholarAction $sendScholar): Response
    {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $sendScholar->execute($game, $player, $request->discipline(), $request->boolean('place'));

        return $this->gameChanged($game);
    }
}
