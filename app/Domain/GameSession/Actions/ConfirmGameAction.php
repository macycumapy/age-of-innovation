<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Actions;

use App\Domain\GameEngine\Interactions\Actions\PerformGameActionOptionAction;
use App\Domain\GameEngine\Turns\Actions\FinishActionTurnAction;
use App\Domain\GameSession\Data\ConfirmedGameActionData;
use App\Domain\GameSession\Enums\GameActionContinuation;
use App\Domain\GameSession\Services\GamePlayerResolver;
use App\Domain\GameSession\Services\PlayerGameActionOptions;
use App\Domain\GameSession\Services\StagedGameActionStore;
use App\Events\GameChanged;
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

    public function execute(User $user, ?int $gameId, int $version): ConfirmedGameActionData
    {
        return DB::transaction(function () use ($user, $gameId, $version): ConfirmedGameActionData {
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

            $continuation = GameActionContinuation::forPlayer($game, $context->player);

            return new ConfirmedGameActionData(
                game: $game,
                action: $staged,
                continuation: $continuation,
                nextPlayerId: $continuation === GameActionContinuation::GameEnded
                    ? null
                    : ($game->state->pendingInteraction->playerId ?? $game->active_game_player_id),
            );
        });
    }
}
