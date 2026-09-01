<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TerrainType;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UndoStartingSpadeAction
{
    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $hexId = $interaction?->context['selectedHexId'] ?? null;
            $terrainBefore = $interaction?->context['terrainBefore'] ?? null;

            if (! in_array($lockedGame->phase, [GamePhase::Setup, GamePhase::Actions, GamePhase::ScienceBonus], true)
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::SpendSpades
                || ! is_string($hexId)
                || ! is_string($terrainBefore)) {
                throw ValidationException::withMessages(['game' => 'Нет преобразования, которое можно отменить.']);
            }

            foreach ($state->board->hexes as $index => $hex) {
                if ($hex->id === $hexId) {
                    $hex->terrain = TerrainType::from($terrainBefore);
                    $state->board->hexes[$index] = $hex;
                    break;
                }
            }

            unset(
                $interaction->context['selectedHexId'],
                $interaction->context['terrainBefore'],
                $interaction->context['terrainAfter'],
            );
            $state->pendingInteraction = $interaction;
            $lockedGame->update(['state' => $state]);

            return $lockedGame->refresh();
        });
    }
}
