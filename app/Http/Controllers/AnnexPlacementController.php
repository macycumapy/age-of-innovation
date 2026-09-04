<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ConfirmAnnexPlacementAction;
use App\Http\Requests\StartAnnexPlacementRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class AnnexPlacementController extends Controller
{
    public function create(StartAnnexPlacementRequest $request, Game $game, ConfirmAnnexPlacementAction $placeAnnex): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $placeAnnex->execute($game, $user, $request->hexId());

        return to_route('games.show', $game);
    }
}
