<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Research\Actions\PerformAdvanceShippingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\AdvanceShippingRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class ShippingAdvancementController extends Controller
{
    public function __invoke(AdvanceShippingRequest $request, Game $game, PerformAdvanceShippingAction $action): Response
    {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $action->execute($game, $player);

        return $this->gameChanged($game);
    }
}
