<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Queries;

use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameSession\Services\GamePlayerResolver;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GetGameBoardLayoutQuery
{
    public function __construct(private GamePlayerResolver $players)
    {
    }

    public function execute(User $user, ?int $gameId = null): BoardStateData
    {
        return DB::transaction(fn (): BoardStateData => $this->players->resolve($user, $gameId)->game->state->board);
    }
}
