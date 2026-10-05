<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Economy\Actions\ExchangeResourcesAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\ExchangeResourcesRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class ResourceExchangeController extends Controller
{
    public function __invoke(
        ExchangeResourcesRequest $request,
        Game $game,
        ExchangeResourcesAction $exchangeResources,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $exchangeResources->execute($game, $player, $request->exchanges());

        return $this->gameChanged($game);
    }
}
