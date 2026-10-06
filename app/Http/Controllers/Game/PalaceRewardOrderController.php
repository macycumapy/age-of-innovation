<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\PlayerAbilities\Actions\ChoosePalaceRewardOrderAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\ChoosePalaceRewardOrderRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class PalaceRewardOrderController extends Controller
{
    public function __invoke(ChoosePalaceRewardOrderRequest $request, Game $game, ChoosePalaceRewardOrderAction $chooseOrder): Response
    {
        /** @var User $user */
        $user = $request->user();
        $chooseOrder->execute($game, $game->players()->whereBelongsTo($user)->firstOrFail(), $request->validated('first_reward'));
        return $this->gameChanged($game);
    }
}
