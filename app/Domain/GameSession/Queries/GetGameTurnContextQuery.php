<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Queries;

use App\Domain\GameSession\Data\GameTurnContextData;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GetGameTurnContextQuery
{
    public function __construct(private GetGameStateQuery $state, private GetLegalGameActionsQuery $actions)
    {
    }

    public function execute(User $user, ?int $gameId = null): GameTurnContextData
    {
        return DB::transaction(function () use ($user, $gameId): GameTurnContextData {
            $view = $this->state->execute($user, $gameId);

            return new GameTurnContextData($view, $this->actions->execute($user, $view->gameId));
        });
    }
}
