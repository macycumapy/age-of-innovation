<?php

declare(strict_types=1);

namespace App\Domain\GameSession\Queries;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameSession\Data\GameTurnWaitResultData;
use App\Domain\GameSession\Enums\GameTurnWaitReason;
use App\Models\Game;
use App\Models\User;
use DomainException;

final class GetGameTurnWaitStatusQuery
{
    public function execute(User $user, ?int $gameId): GameTurnWaitResultData
    {
        $games = Game::query()->turnWaitStatusesFor($user, $gameId)->get();

        if ($games->isEmpty()) {
            throw new DomainException('The user does not participate in this game or has not joined any game yet.');
        }
        if ($games->count() > 1) {
            throw new DomainException('The user participates in multiple games. Specify game_id.');
        }

        $game = $games->firstOrFail();
        $reason = GameTurnWaitReason::Timeout;

        if (in_array($game->status, [GameStatus::Finished, GameStatus::Abandoned], true)) {
            $reason = GameTurnWaitReason::GameEnded;
        } elseif ($game->status === GameStatus::Active) {
            $playerId = (int) $game->getAttribute('waiting_player_id');
            $pendingPlayerId = $game->getAttribute('pending_player_id');
            $isActivePlayer = $game->active_game_player_id === $playerId
                || ($game->active_game_player_id === null && $game->active_player_id === $user->id);

            if ($pendingPlayerId !== null ? (int) $pendingPlayerId === $playerId : $isActivePlayer) {
                $reason = GameTurnWaitReason::YourTurn;
            }
        }

        return new GameTurnWaitResultData(reason: $reason, gameId: $game->id);
    }
}
