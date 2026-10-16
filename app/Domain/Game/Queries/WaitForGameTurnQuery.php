<?php

declare(strict_types=1);

namespace App\Domain\Game\Queries;

use App\Domain\Game\Data\GameTurnWaitResultData;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Services\GamePlayerResolver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

final class WaitForGameTurnQuery
{
    public function __construct(private GamePlayerResolver $players, private GetGameStateQuery $state)
    {
    }

    public function execute(User $user, ?int $gameId = null, int $timeoutSeconds = 20): GameTurnWaitResultData
    {
        if ($timeoutSeconds < 0 || $timeoutSeconds > 20) {
            throw new InvalidArgumentException('The wait timeout must be between 0 and 20 seconds.');
        }

        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;

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
            if ($result->reason !== 'timeout' || $attempt >= $timeoutSeconds || hrtime(true) >= $deadline) {
                return $result;
            }

            $remainingMicroseconds = max(0, (int) ceil(($deadline - hrtime(true)) / 1000));
            Sleep::for(min(1_000_000, $remainingMicroseconds))->microseconds();
        }
    }
}
