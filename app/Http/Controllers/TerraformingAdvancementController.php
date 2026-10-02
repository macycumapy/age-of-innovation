<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\GameEngine\Research\Actions\PerformAdvanceTerraformingAction;
use App\Http\Requests\AdvanceTerraformingRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class TerraformingAdvancementController extends Controller
{
    public function __invoke(
        AdvanceTerraformingRequest $request,
        Game $game,
        PerformAdvanceTerraformingAction $action,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $action->execute($game, $player);

        return $this->gameChanged($game);
    }
}
