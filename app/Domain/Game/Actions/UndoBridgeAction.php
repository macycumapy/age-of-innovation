<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UndoBridgeAction
{
    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::PlaceBridge
                || ! isset($interaction->context['selectedFromHexId'])) {
                throw ValidationException::withMessages(['bridge' => 'Нет выбранного моста, который можно отменить.']);
            }

            unset($interaction->context['selectedFromHexId'], $interaction->context['selectedToHexId']);
            $state->pendingInteraction = $interaction;
            $lockedGame->update(['state' => $state]);

            return $lockedGame->refresh();
        });
    }
}
