<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Economy\Actions\ResolvePowerOfferAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\ResolvePowerOfferRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class PowerOfferController extends Controller
{
    public function __invoke(
        ResolvePowerOfferRequest $request,
        Game $game,
        ResolvePowerOfferAction $resolvePowerOffer,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $resolvePowerOffer->execute($game, $player, $request->boolean('accept'));

        return $this->gameChanged($game);
    }
}
