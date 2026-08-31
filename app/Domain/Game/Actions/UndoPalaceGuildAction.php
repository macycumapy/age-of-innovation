<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UndoPalaceGuildAction
{
    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $selectedHexId = $interaction?->context['selectedHexId'] ?? null;

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::PlacePalaceGuild
                || ! is_string($selectedHexId)) {
                throw ValidationException::withMessages(['game' => 'Нет размещения рынка, которое можно отменить.']);
            }

            $hex = collect($state->board->hexes)->firstWhere('id', $selectedHexId);

            if ($hex !== null) {
                $hex->building = null;
            }

            $interaction->context['selectedHexId'] = null;
            $state->pendingInteraction = $interaction;
            $lockedGame->update(['state' => $state]);

            return $lockedGame->refresh();
        });
    }
}
