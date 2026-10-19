<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Queries;

use App\Domain\GameSession\Data\GameActionOptionsData;
use App\Domain\GameSession\Services\GamePlayerResolver;
use App\Domain\GameSession\Services\PlayerGameActionOptions;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class GetLegalGameActionsQuery
{
    public function __construct(
        private GamePlayerResolver $players,
        private PlayerGameActionOptions $options,
    ) {
    }

    public function execute(User $user, ?int $gameId = null): GameActionOptionsData
    {
        return DB::transaction(function () use ($user, $gameId): GameActionOptionsData {
            $context = $this->players->resolve($user, $gameId);

            return $this->options->get($context->game, $context->player);
        });
    }
}
