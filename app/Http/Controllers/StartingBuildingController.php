<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PlaceStartingBuildingAction;
use App\Domain\Game\Actions\UndoStartingBuildingAction;
use App\Http\Requests\PlaceStartingBuildingRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class StartingBuildingController extends Controller
{
    public function store(
        PlaceStartingBuildingRequest $request,
        Game $game,
        PlaceStartingBuildingAction $placeStartingBuilding,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $placeStartingBuilding->execute($game, $user, (string) $request->validated('hex_id'));

        return to_route('games.show', $game);
    }

    public function destroy(
        Request $request,
        Game $game,
        UndoStartingBuildingAction $undoStartingBuilding,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $undoStartingBuilding->execute($game, $user);

        return to_route('games.show', $game);
    }
}
