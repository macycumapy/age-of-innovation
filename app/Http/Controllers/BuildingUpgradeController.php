<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\UpgradeBuildingAction;
use App\Domain\Game\Enums\BuildingType;
use App\Http\Requests\UpgradeBuildingRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class BuildingUpgradeController extends Controller
{
    public function __invoke(
        UpgradeBuildingRequest $request,
        Game $game,
        UpgradeBuildingAction $upgradeBuilding,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $upgradeBuilding->execute(
            $game,
            $user,
            $request->string('hex_id')->toString(),
            BuildingType::from($request->string('target')->toString()),
        );

        return to_route('games.show', $game);
    }
}
