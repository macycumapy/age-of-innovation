<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ExchangeResourcesAction;
use App\Http\Requests\ExchangeResourcesRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class ResourceExchangeController extends Controller
{
    public function __invoke(
        ExchangeResourcesRequest $request,
        Game $game,
        ExchangeResourcesAction $exchangeResources,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $exchangeResources->execute($game, $user, $request->exchanges());

        return to_route('games.show', $game);
    }
}
