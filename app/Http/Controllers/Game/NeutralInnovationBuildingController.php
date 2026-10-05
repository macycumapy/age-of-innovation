<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Board\Actions\PlaceNeutralInnovationBuildingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\PlaceNeutralInnovationBuildingRequest;
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
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $placeNeutralBuilding->execute($game, $player, $request->boolean('skip') ? null : $request->string('hex_id')->toString());

        return $this->gameChanged($game);
    }
}
