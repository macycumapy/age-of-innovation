<?php

declare(strict_types=1);

namespace App\Domain\Game\Queries;

use App\Domain\Game\Data\GameActionOptionsData;
use App\Domain\Game\Services\GamePlayerResolver;
use App\Domain\Game\Services\PlayerGameActionOptions;
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
