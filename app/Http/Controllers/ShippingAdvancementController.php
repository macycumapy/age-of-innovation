<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PerformAdvanceShippingAction;
use App\Http\Requests\AdvanceShippingRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class ShippingAdvancementController extends Controller
{
    public function __invoke(AdvanceShippingRequest $request, Game $game, PerformAdvanceShippingAction $action): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $action->execute($game, $user);

        return to_route('games.show', $game);
    }
}
