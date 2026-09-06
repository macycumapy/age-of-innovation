<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\SpendStartingSpadeAction;
use App\Domain\Game\Actions\StartPaidTerraformingAction;
use App\Http\Requests\StartPaidTerraformingRequest;
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
        DB::transaction(function () use ($game, $user, $request, $startPaidTerraforming, $spendStartingSpade): void {
            $preparedGame = $startPaidTerraforming->execute(
                $game,
                $user,
                $request->hexId(),
                $request->useAvailable(),
            );
            $spendStartingSpade->execute($preparedGame, $user, $request->hexId());
        });

        return $this->gameChanged($game);
    }
}
