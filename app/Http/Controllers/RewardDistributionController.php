<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\DistributeRewardsAction;
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
        $distributeRewards->execute(
            $game,
            $user,
            $request->bookCounts(),
            $request->knowledgeCounts(),
            $request->competency(),
        );

        return $this->gameChanged($game);
    }
}
