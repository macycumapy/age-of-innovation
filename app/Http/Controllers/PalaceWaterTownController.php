<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ResolvePalaceWaterTownAction;
use App\Http\Requests\ResolvePalaceWaterTownRequest;
use App\Models\Game;
use Illuminate\Http\RedirectResponse;

final class PalaceWaterTownController extends Controller
{
    public function __invoke(
        ResolvePalaceWaterTownRequest $request,
        Game $game,
        ResolvePalaceWaterTownAction $resolvePalaceWaterTown,
    ): RedirectResponse {
        $resolvePalaceWaterTown->execute(
            $game,
            $request->user(),
            $request->boolean('accept'),
            $request->validated('water_hex_id'),
        );

        return to_route('games.show', $game);
    }
}
