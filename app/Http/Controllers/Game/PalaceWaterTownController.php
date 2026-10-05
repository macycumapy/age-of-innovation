<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Towns\Actions\ResolvePalaceWaterTownAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\ResolvePalaceWaterTownRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class PalaceWaterTownController extends Controller
{
    public function __invoke(
        ResolvePalaceWaterTownRequest $request,
        Game $game,
        ResolvePalaceWaterTownAction $resolvePalaceWaterTown,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $resolvePalaceWaterTown->execute(
            $game,
            $player,
            $request->boolean('accept'),
            $request->validated('water_hex_id'),
        );

        return $this->gameChanged($game);
    }
}
