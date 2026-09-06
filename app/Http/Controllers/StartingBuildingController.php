<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PlaceStartingBuildingAction;
use App\Domain\Game\Actions\UndoStartingBuildingAction;
use App\Http\Requests\PlaceStartingBuildingRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class StartingBuildingController extends Controller
{
    public function store(
        PlaceStartingBuildingRequest $request,
        Game $game,
        PlaceStartingBuildingAction $placeStartingBuilding,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $placeStartingBuilding->execute($game, $user, (string) $request->validated('hex_id'));

        return $this->gameChanged($game);
    }

    public function destroy(
        Request $request,
        Game $game,
        UndoStartingBuildingAction $undoStartingBuilding,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $undoStartingBuilding->execute($game, $user);

        return $this->gameChanged($game);
    }
}
