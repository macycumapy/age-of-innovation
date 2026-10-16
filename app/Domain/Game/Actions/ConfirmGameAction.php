<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Services\GamePlayerResolver;
use App\Domain\Game\Services\PlayerGameActionOptions;
use App\Domain\Game\Services\StagedGameActionStore;
use App\Domain\GameEngine\Interactions\Actions\PerformGameActionOptionAction;
use App\Domain\GameEngine\Turns\Actions\FinishActionTurnAction;
use App\Events\GameChanged;
use App\Models\Game;
use App\Models\User;
use DomainException;
use Illuminate\Support\Facades\DB;

final class ConfirmGameAction
{
    public function __construct(
        private GamePlayerResolver $players,
        private PlayerGameActionOptions $options,
        private StagedGameActionStore $stagedActions,
        private PerformGameActionOptionAction $perform,
        private FinishActionTurnAction $finish,
    ) {
    }

    public function execute(User $user, ?int $gameId, int $version): Game
    {
        return DB::transaction(function () use ($user, $gameId, $version): Game {
            $context = $this->players->resolve($user, $gameId, $version);
            $staged = $this->stagedActions->get($context->player);
            if ($staged === null || $staged->version !== $version
                || ! $this->options->get($context->game, $context->player)->allows($staged->option)) {
                throw new DomainException('No valid staged action. Stage an action first.');
            }
            $game = $staged->option === null
                ? $this->finish->execute($context->game, $context->player)
                : $this->perform->execute($context->game, $context->player, $staged->option);
            $this->stagedActions->forget($context->player);
            DB::afterCommit(static fn () => GameChanged::dispatch($game->id));

            return $game;
        });
    }
}
