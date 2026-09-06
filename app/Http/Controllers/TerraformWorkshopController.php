<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\ResolveWorkshopAfterTerraformingAction;
use App\Http\Requests\ResolveWorkshopAfterTerraformingRequest;
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
        $resolveWorkshop->execute(
            $game,
            $user,
            $request->boolean('build'),
            $request->string('hex_id')->toString() ?: null,
        );

        return $this->gameChanged($game);
    }
}
