<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\GameEngine\Interactions\Actions\DistributeRewardsAction;
use App\Http\Requests\DistributeRewardsRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class RewardDistributionController extends Controller
{
    public function __invoke(
        DistributeRewardsRequest $request,
        Game $game,
        DistributeRewardsAction $distributeRewards,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $distributeRewards->execute(
            $game,
            $player,
            $request->bookCounts(),
            $request->knowledgeCounts(),
            $request->competency(),
        );

        return $this->gameChanged($game);
    }
}
