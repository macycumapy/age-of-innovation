<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\History\Actions;

use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class RestartCurrentTurnAction
{
    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $turnStartVersion = $lockedGame->state->round->turnStartVersion;
            $turnStartSnapshot = $lockedGame->state->turnStartSnapshot;

            if (! $lockedGame->phase->isActionPhase()
                || $lockedGame->active_player_id !== $user->id
                || ! $player instanceof GamePlayer
                || $lockedGame->state->pendingInteraction?->type === PendingInteractionType::PowerOffer
                || $turnStartVersion === null
                || ! is_array($turnStartSnapshot)) {
                throw ValidationException::withMessages([
                    'game' => $lockedGame->state->pendingInteraction?->type === PendingInteractionType::PowerOffer
                        ? 'Сначала сосед должен ответить на предложение Силы.'
                        : 'В текущем ходу пока нет действий для отката.',
                ]);
            }

            $currentTurnActionIds = $lockedGame->actions()
                ->lockForUpdate()
                ->where('state_version_before', '>=', $turnStartVersion)
                ->pluck('id')
                ->all();
            $restoredState = GameStateData::from($turnStartSnapshot);
            if ($restoredState->pendingInteraction !== null) {
                $restoredState->turnStartSnapshot = $turnStartSnapshot;
            }
            $lockedGame->update([
                'state' => $restoredState,
                'version' => $turnStartVersion,
            ]);

            if ($currentTurnActionIds !== []) {
                $lockedGame->actions()->whereKey($currentTurnActionIds)->delete();
            }

            return $lockedGame->refresh();
        });
    }
}
