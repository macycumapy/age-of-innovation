<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\GameActionType;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UndoTownChoiceAction
{
    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);

            $townChoice = $lockedGame->actions()->lockForUpdate()->latest('sequence')->first();
            $checkpoint = $lockedGame->state->townChoiceCheckpoint;

            if (! $townChoice instanceof GameAction
                || $townChoice->type !== GameActionType::ChooseTown
                || $townChoice->player_id !== $user->id
                || $lockedGame->active_player_id !== $user->id
                || ! is_array($checkpoint)) {
                throw ValidationException::withMessages([
                    'town' => 'Последний выбор жетона города нельзя отменить.',
                ]);
            }

            $lockedGame->update([
                'active_player_id' => $user->id,
                'state' => GameStateData::from($checkpoint),
                'version' => $townChoice->state_version_before,
            ]);
            $townChoice->delete();

            return $lockedGame->refresh();
        });
    }
}
