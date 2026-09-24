<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinishActionTurnAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyFinishActionTurnAction $applyFinishActionTurn,
    ) {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();

            if (! $lockedGame->phase->isActionPhase()
                || $lockedGame->active_player_id !== $user->id
                || ($state->pendingInteraction !== null
                    && ($state->pendingInteraction->type !== PendingInteractionType::BuildWorkshopAfterTerraforming
                        || $state->pendingInteraction->playerId !== $player?->id))
                || $state->round->turnStartVersion === null
                || ! $state->round->hasTakenMainAction
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя завершить ход.']);
            }

            $stateVersionBefore = $lockedGame->version;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }

            $nextActiveUserId = $this->applyFinishActionTurn->execute($state, $playerState);
            $nextPlayer = $lockedGame->players()->where('user_id', $nextActiveUserId)->firstOrFail();
            $lockedGame->update([
                'active_player_id' => $nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::FinishTurn,
                ['next_player_id' => $nextPlayer->id],
                [[
                    'type' => GameEventType::TurnFinished->value,
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
