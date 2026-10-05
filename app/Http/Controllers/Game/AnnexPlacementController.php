<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Board\Actions\ConfirmAnnexPlacementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\StartAnnexPlacementRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class AnnexPlacementController extends Controller
{
    public function create(StartAnnexPlacementRequest $request, Game $game, ConfirmAnnexPlacementAction $placeAnnex): Response
    {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $placeAnnex->execute($game, $player, $request->hexId());

        return $this->gameChanged($game);
    }
}
