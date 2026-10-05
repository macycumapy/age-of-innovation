<?php

declare(strict_types=1);

namespace App\Http\Controllers\Game;

use App\Domain\GameEngine\Board\Actions\ResolveWorkshopAfterTerraformingAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Game\ResolveWorkshopAfterTerraformingRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class TerraformWorkshopController extends Controller
{
    public function __invoke(
        ResolveWorkshopAfterTerraformingRequest $request,
        Game $game,
        ResolveWorkshopAfterTerraformingAction $resolveWorkshop,
    ): Response {
        /** @var User $user */
        $user = $request->user();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $resolveWorkshop->execute(
            $game,
            $player,
            $request->boolean('build'),
            $request->string('hex_id')->toString() ?: null,
        );

        return $this->gameChanged($game);
    }
}
