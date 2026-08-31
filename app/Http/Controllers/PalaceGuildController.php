<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PlacePalaceGuildAction;
use App\Domain\Game\Actions\UndoPalaceGuildAction;
use App\Http\Requests\PlacePalaceGuildRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class PalaceGuildController extends Controller
{
    public function store(
        PlacePalaceGuildRequest $request,
        Game $game,
        PlacePalaceGuildAction $placePalaceGuild,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $placePalaceGuild->execute($game, $user, (string) $request->validated('hex_id'));

        return to_route('games.show', $game);
    }

    public function destroy(Request $request, Game $game, UndoPalaceGuildAction $undoPalaceGuild): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $undoPalaceGuild->execute($game, $user);

        return to_route('games.show', $game);
    }
}
