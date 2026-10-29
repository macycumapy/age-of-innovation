<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Actions;

use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameSession\Data\ConfirmedGameActionData;
use App\Domain\GameSession\Services\GamePlayerResolver;
use App\Domain\GameSession\Services\StagedGameActionStore;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ExecuteGameAction
{
    public function __construct(
        private GamePlayerResolver $players,
        private StagedGameActionStore $stagedActions,
        private StageGameAction $stage,
        private ConfirmGameAction $confirm,
    ) {
    }

    public function execute(User $user, ?int $gameId, int $version, ?GameActionOption $option): ConfirmedGameActionData
    {
        return DB::transaction(function () use ($user, $gameId, $version, $option): ConfirmedGameActionData {
            $context = $this->players->resolve($user, $gameId, $version);
            if ($this->stagedActions->get($context->player) !== null) {
                throw new DomainException('A staged action already exists. Confirm or roll it back before executing another action.');
            }

            try {
                $this->stage->execute($user, $context->game->id, $version, $option);

                return $this->confirm->execute($user, $context->game->id, $version);
            } catch (Throwable $exception) {
                $this->stagedActions->forget($context->player);

                throw $exception;
            }
        });
    }
}
