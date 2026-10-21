<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Queries;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameSession\Data\GameTurnWaitResultData;
use App\Domain\GameSession\Enums\GameTurnWaitReason;
use App\Domain\GameSession\Services\GamePlayerResolver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

final class WaitForGameTurnQuery
{
    public const int TIMEOUT_SECONDS = 30;

    public function __construct(
        private GamePlayerResolver $players,
        private GetGameStateQuery $state,
        private GetLegalGameActionsQuery $actions,
    ) {
    }

    public function execute(User $user, ?int $gameId = null, int $timeoutSeconds = self::TIMEOUT_SECONDS): GameTurnWaitResultData
    {
        if ($timeoutSeconds < 0 || $timeoutSeconds > self::TIMEOUT_SECONDS) {
            throw new InvalidArgumentException('The wait timeout must be between 0 and '.self::TIMEOUT_SECONDS.' seconds.');
        }

        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;

        for ($attempt = 0; ; $attempt++) {
            $result = DB::transaction(function () use ($user, $gameId): GameTurnWaitResultData {
                $context = $this->players->resolve($user, $gameId);
                $game = $context->game;
                $reason = GameTurnWaitReason::Timeout;

                if (in_array($game->status, [GameStatus::Finished, GameStatus::Abandoned], true)) {
                    $reason = GameTurnWaitReason::GameEnded;
                } elseif ($game->status === GameStatus::Active) {
                    $pending = $game->state->pendingInteraction;
                    if ($pending !== null ? $pending->playerId === $context->player->id : $game->isActivePlayer($context->player)) {
                        $reason = GameTurnWaitReason::YourTurn;
                    }
                }

                return new GameTurnWaitResultData(
                    reason: $reason,
                    gameId: $game->id,
                    view: $reason === GameTurnWaitReason::Timeout ? null : $this->state->execute($user, $game->id),
                    actions: $reason === GameTurnWaitReason::YourTurn ? $this->actions->execute($user, $game->id) : null,
                );
            });

            $gameId = $result->gameId;
            if ($result->reason !== GameTurnWaitReason::Timeout) {
                return $result;
            }

            if ($attempt >= $timeoutSeconds || hrtime(true) >= $deadline) {
                return $result;
            }

            $remainingMicroseconds = max(0, (int) ceil(($deadline - hrtime(true)) / 1000));
            Sleep::for(min(1_000_000, $remainingMicroseconds))->microseconds();
        }
    }
}
