<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UndoStartingBuildingAction
{
    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;

            if ($lockedGame->phase !== GamePhase::Setup || $lockedGame->active_player_id !== $user->id
                || $state->pendingStartingBuildingHexId === null) {
                throw ValidationException::withMessages(['game' => 'Нет стартового дома, который можно отменить.']);
            }

            foreach ($state->board->hexes as $index => $hex) {
                if ($hex->id === $state->pendingStartingBuildingHexId) {
                    $hex->building = null;
                    $state->board->hexes[$index] = $hex;
                    break;
                }
            }

            $state->pendingStartingBuildingHexId = null;
            $lockedGame->update(['state' => $state]);

            return $lockedGame->refresh();
        });
    }
}
