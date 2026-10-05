<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Board\Actions\SpendStartingSpadeAction;
use App\Domain\GameEngine\Board\Actions\StartPaidTerraformingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\StartPaidTerraformingRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;

final class PaidTerraformingController extends Controller
{
    public function __invoke(
        StartPaidTerraformingRequest $request,
        Game $game,
        StartPaidTerraformingAction $startPaidTerraforming,
        SpendStartingSpadeAction $spendStartingSpade,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        DB::transaction(function () use ($game, $player, $request, $startPaidTerraforming, $spendStartingSpade): void {
            $preparedGame = $startPaidTerraforming->execute(
                $game,
                $player,
                $request->hexId(),
                $request->useAvailable(),
                $request->useTunnel(),
                $request->useFlight(),
            );
            $spendStartingSpade->execute($preparedGame, $player, $request->hexId());
        });

        return $this->gameChanged($game);
    }
}
