<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Game\Actions\PassAction;
use App\Http\Requests\PassRequest;
use App\Models\Game;
use App\Models\User;
use Illuminate\Http\Response;

final class PassController extends Controller
{
    public function __invoke(PassRequest $request, Game $game, PassAction $pass): Response
    {
        /** @var User $user */
        $user = $request->user();
        $pass->execute($game, $user, $request->knowledgeDisciplines());

        return $this->gameChanged($game);
    }
}
