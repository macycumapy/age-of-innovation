<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\BuildWorkshopAction;
use App\Http\Requests\BuildWorkshopRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class WorkshopController extends Controller
{
    public function __invoke(BuildWorkshopRequest $request, Game $game, BuildWorkshopAction $buildWorkshop): Response
    {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $buildWorkshop->execute($game, $player, $request->hexId());

        return $this->gameChanged($game);
    }
}
