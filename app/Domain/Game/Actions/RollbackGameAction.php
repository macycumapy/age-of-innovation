<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Services\GamePlayerResolver;
use App\Domain\Game\Services\StagedGameActionStore;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class RollbackGameAction
{
    public function __construct(
        private GamePlayerResolver $players,
        private StagedGameActionStore $stagedActions,
    ) {
    }

    public function execute(User $user, ?int $gameId, int $version): Game
    {
        return DB::transaction(function () use ($user, $gameId, $version): Game {
            $context = $this->players->resolve($user, $gameId, $version);
            $this->stagedActions->forget($context->player);

            return $context->game;
        });
    }
}
