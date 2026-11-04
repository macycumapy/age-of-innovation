<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Queries;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameSession\Data\GameTurnWaitResultData;
use App\Domain\GameSession\Enums\GameTurnWaitReason;
use App\Domain\GameSession\Services\GameChangeSignal;
use App\Domain\GameSession\Services\GamePlayerResolver;
use App\Domain\GameSession\Services\GameWaitObserver;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Sleep;
use InvalidArgumentException;

final class WaitForGameTurnQuery
{
    public const int TIMEOUT_SECONDS = 30;

    public function __construct(
        private GetGameTurnWaitStatusQuery $status,
        private GamePlayerResolver $players,
        private GetGameStateQuery $state,
        private GetLegalGameActionsQuery $actions,
        private GameChangeSignal $signals,
        private GameWaitObserver $observer,
    ) {
    }

    public function execute(User $user, ?int $gameId = null, int $timeoutSeconds = self::TIMEOUT_SECONDS): GameTurnWaitResultData
    {
        if ($timeoutSeconds < 0 || $timeoutSeconds > self::TIMEOUT_SECONDS) {
            throw new InvalidArgumentException('The wait timeout must be between 0 and '.self::TIMEOUT_SECONDS.' seconds.');
        }

        $deadline = hrtime(true) + $timeoutSeconds * 1_000_000_000;

        $result = $this->checkForTurn($user, $gameId);
        $gameId = $result->gameId;

        if ($result->reason !== GameTurnWaitReason::Timeout || $timeoutSeconds === 0) {
            return $result;
        }

        $cursor = $this->signals->cursor($gameId);
        if ($cursor !== null) {
            /** Capture the cursor before the last database check to retain concurrent notifications. */
            $result = $this->checkForTurn($user, $gameId);
            if ($result->reason !== GameTurnWaitReason::Timeout) {
                return $result;
            }
        }

        $this->observer->waiting($gameId, $cursor !== null);

        $remainingMilliseconds = $timeoutSeconds * 1000;

        while ($remainingMilliseconds > 0) {
            $remainingMilliseconds = min($remainingMilliseconds, max(0, (int) ceil(($deadline - hrtime(true)) / 1_000_000)));
            if ($remainingMilliseconds === 0) {
                return $this->checkForTurn($user, $gameId);
            }

            if ($cursor === null) {
                $sleepMilliseconds = min(1000, $remainingMilliseconds);
                Sleep::for($sleepMilliseconds)->milliseconds();
                $remainingMilliseconds -= $sleepMilliseconds;
            } else {
                $nextCursor = $this->signals->wait($gameId, $cursor, $remainingMilliseconds);
                if ($nextCursor === $cursor) {
                    $remainingMilliseconds = 0;
                }
                if ($nextCursor === null) {
                    $this->observer->notificationsUnavailable($gameId);
                }
                $cursor = $nextCursor;
            }

            $result = $this->checkForTurn($user, $gameId);
            if ($result->reason !== GameTurnWaitReason::Timeout) {
                return $result;
            }
        }

        return $result;
    }

    private function checkForTurn(User $user, ?int $gameId): GameTurnWaitResultData
    {
        $result = $this->status->execute($user, $gameId);

        return $result->reason === GameTurnWaitReason::Timeout ? $result : $this->readyResult($user, $result->gameId);
    }

    private function readyResult(User $user, int $gameId): GameTurnWaitResultData
    {
        return DB::transaction(function () use ($user, $gameId): GameTurnWaitResultData {
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
    }
}
