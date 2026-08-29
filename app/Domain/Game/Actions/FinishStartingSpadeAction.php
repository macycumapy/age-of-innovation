<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinishStartingSpadeAction
{
    public function __construct(private AppendGameHistoryAction $appendGameHistory)
    {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $hexId = $interaction?->context['selectedHexId'] ?? null;
            $stateVersionBefore = $lockedGame->version;
            $player = $lockedGame->players()
                ->whereKey($interaction?->playerId)
                ->whereBelongsTo($user)
                ->first();

            if ($lockedGame->phase !== GamePhase::Setup
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::SpendSpades
                || ! is_string($hexId)
                || ! $player instanceof GamePlayer) {
                throw ValidationException::withMessages(['game' => 'Сначала выберите клетку для преобразования.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if ($playerState === null || $playerState->unassignedSpades < 1) {
                throw ValidationException::withMessages(['game' => 'У игрока нет доступной стартовой лопаты.']);
            }

            $playerState->unassignedSpades--;
            $state->pendingInteraction = null;
            $state->round->phase = GamePhase::Income;
            $nextPlayer = $lockedGame->players()->whereKey($state->turnOrder[0])->firstOrFail();
            $lockedGame->update([
                'phase' => GamePhase::Income,
                'active_player_id' => $nextPlayer->user_id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::SpendStartingSpade,
                [
                    'hex_id' => $hexId,
                    'terrain_before' => $interaction->context['terrainBefore'] ?? null,
                    'terrain_after' => $interaction->context['terrainAfter'] ?? null,
                ],
                [['type' => 'starting_spade_spent', 'player_id' => $player->id, 'hex_id' => $hexId]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
