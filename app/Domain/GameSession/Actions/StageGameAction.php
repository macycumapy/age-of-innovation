<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Actions;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameSession\Data\StagedGameActionData;
use App\Domain\GameSession\Services\GamePlayerResolver;
use App\Domain\GameSession\Services\PlayerGameActionOptions;
use App\Domain\GameSession\Services\StagedGameActionStore;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class StageGameAction
{
    public function __construct(
        private GamePlayerResolver $players,
        private PlayerGameActionOptions $options,
        private StagedGameActionStore $stagedActions,
    ) {
    }

    public function execute(User $user, ?int $gameId, int $version, ?GameActionOption $option): StagedGameActionData
    {
        return DB::transaction(function () use ($user, $gameId, $version, $option): StagedGameActionData {
            $context = $this->players->resolve($user, $gameId, $version);
            if (! $this->options->get($context->game, $context->player)->allows($option)) {
                throw new DomainException('The selected game action is not available.');
            }
            $staged = new StagedGameActionData($version, $option);
            $this->stagedActions->put($context->player, $staged);

            return $staged;
        });
    }
}
