<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Board\Actions\UpgradeBuildingAction;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\UpgradeBuildingRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class BuildingUpgradeController extends Controller
{
    public function __invoke(
        UpgradeBuildingRequest $request,
        Game $game,
        UpgradeBuildingAction $upgradeBuilding,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $upgradeBuilding->execute(
            $game,
            $player,
            $request->string('hex_id')->toString(),
            BuildingType::from($request->string('target')->toString()),
        );

        return $this->gameChanged($game);
    }
}
