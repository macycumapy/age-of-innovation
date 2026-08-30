<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinishActionTurnAction
{
    public function __construct(private AppendGameHistoryAction $appendGameHistory)
    {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->turnStartVersion === null
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя завершить ход.']);
            }

            $currentIndex = array_search($player->id, $state->turnOrder, true);
            $nextPlayerId = null;

            if ($currentIndex !== false) {
                foreach (range(1, count($state->turnOrder)) as $offset) {
                    $candidateId = $state->turnOrder[($currentIndex + $offset) % count($state->turnOrder)];

                    if (! in_array($candidateId, $state->passedPlayerIds, true)) {
                        $nextPlayerId = $candidateId;
                        break;
                    }
                }
            }

            $nextPlayer = $lockedGame->players()->whereKey($nextPlayerId)->first();

            if (! $nextPlayer instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Не удалось определить следующего игрока.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $state->turnStartSnapshot = null;
            $state->round->turnStartVersion = null;
            $lockedGame->update([
                'active_player_id' => $nextPlayer->user_id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::FinishTurn,
                ['next_player_id' => $nextPlayer->id],
                [[
                    'type' => 'turn_finished',
                    'player_id' => $player->id,
                    'next_player_id' => $nextPlayer->id,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
