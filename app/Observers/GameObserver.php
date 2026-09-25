<?php

declare(strict_types=1);

namespace App\Observers;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Jobs\PlayAutomatedTurnJob;
use App\Models\Game;
use App\Models\GamePlayer;

final class GameObserver
{
    public function updated(Game $game): void
    {
        if (! $game->wasChanged('active_game_player_id')
            || $game->active_game_player_id === null
            || $game->status !== GameStatus::Active
            || (! $game->phase->isActionPhase() && ! $this->supportsSetupDecision($game))) {
            return;
        }

        $player = $game->players()->find($game->active_game_player_id);

        if ($player instanceof GamePlayer && $player->bot_difficulty !== null) {
            PlayAutomatedTurnJob::dispatch($game->id, $player->id);
        }
    }

    private function supportsSetupDecision(Game $game): bool
    {
        if ($game->phase !== GamePhase::Setup || $game->active_game_player_id === null) {
            return false;
        }

        $hasPlayerState = collect($game->state->players)
            ->contains('playerId', $game->active_game_player_id);

        return ! $hasPlayerState || $game->state->pendingInteraction !== null;
    }
}
