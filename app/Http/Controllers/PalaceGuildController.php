<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PlacePalaceGuildAction;
use App\Domain\Game\Actions\UndoPalaceGuildAction;
use App\Http\Requests\PlacePalaceGuildRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class PalaceGuildController extends Controller
{
    public function store(
        PlacePalaceGuildRequest $request,
        Game $game,
        PlacePalaceGuildAction $placePalaceGuild,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $placePalaceGuild->execute($game, $user, (string) $request->validated('hex_id'));

        return $this->gameChanged($game);
    }

    public function destroy(Request $request, Game $game, UndoPalaceGuildAction $undoPalaceGuild): Response
    {
        /** @var User $user */
        $user = $request->user();
        $undoPalaceGuild->execute($game, $user);

        return $this->gameChanged($game);
    }
}
