<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PlaceNeutralInnovationBuildingAction;
use App\Http\Requests\PlaceNeutralInnovationBuildingRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class NeutralInnovationBuildingController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(
        PlaceNeutralInnovationBuildingRequest $request,
        Game $game,
        PlaceNeutralInnovationBuildingAction $placeNeutralBuilding,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $placeNeutralBuilding->execute($game, $user, $request->string('hex_id')->toString());

        return $this->gameChanged($game);
    }

}
