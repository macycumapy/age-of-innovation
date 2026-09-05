<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PerformAdvanceTerraformingAction;
use App\Http\Requests\AdvanceTerraformingRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\RedirectResponse;

final class TerraformingAdvancementController extends Controller
{
    public function __invoke(
        AdvanceTerraformingRequest $request,
        Game $game,
        PerformAdvanceTerraformingAction $action,
    ): RedirectResponse {
        /** @var User $user */
        $user = $request->user();
        $action->execute($game, $user);

        return to_route('games.show', $game);
    }
}
