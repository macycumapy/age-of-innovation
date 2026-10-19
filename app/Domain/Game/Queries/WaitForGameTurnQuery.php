<?php

declare(strict_types=1);

namespace App\Domain\Game\Queries;

use App\Domain\Game\Data\GameTurnWaitResultData;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Services\GamePlayerResolver;
use App\Domain\Game\Services\GameTurnWaitBackoff;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

final class WaitForGameTurnQuery
{
    public function __construct(private GamePlayerResolver $players, private GetGameStateQuery $state, private GameTurnWaitBackoff $backoff)
    {
    }

    public function execute(User $user, ?int $gameId = null, ?int $timeoutSeconds = null): GameTurnWaitResultData
    {
        if ($timeoutSeconds !== null && ($timeoutSeconds < 0 || $timeoutSeconds > GameTurnWaitBackoff::MAXIMUM_SECONDS)) {
            throw new InvalidArgumentException('The wait timeout must be between 0 and '.GameTurnWaitBackoff::MAXIMUM_SECONDS.' seconds.');
        }

        $startedAt = hrtime(true);
        $waitSeconds = $timeoutSeconds;

        for ($attempt = 0; ; $attempt++) {
            $result = DB::transaction(function () use ($user, $gameId): GameTurnWaitResultData {
                $context = $this->players->resolve($user, $gameId);
                $game = $context->game;
                $reason = 'timeout';

                if (in_array($game->status, [GameStatus::Finished, GameStatus::Abandoned], true)) {
                    $reason = 'game_ended';
                } elseif ($game->status === GameStatus::Active) {
                    $pending = $game->state->pendingInteraction;
                    if ($pending !== null ? $pending->playerId === $context->player->id : $game->isActivePlayer($context->player)) {
                        $reason = 'your_turn';
                    }
                }

                return new GameTurnWaitResultData($reason, $this->state->execute($user, $game->id));
            });

            $gameId = $result->view->gameId;
            if ($result->reason !== 'timeout') {
                $this->backoff->reset($user->id, $gameId);

                return $result;
            }

            $waitSeconds ??= $this->backoff->seconds($user->id, $gameId);
            $deadline = $startedAt + $waitSeconds * 1_000_000_000;
            if ($attempt >= $waitSeconds || hrtime(true) >= $deadline) {
                if ($timeoutSeconds === null) {
                    $this->backoff->increase($user->id, $gameId, $waitSeconds);
                }

                return $result;
            }

            $remainingMicroseconds = max(0, (int) ceil(($deadline - hrtime(true)) / 1000));
            Sleep::for(min(1_000_000, $remainingMicroseconds))->microseconds();
        }
    }
}
