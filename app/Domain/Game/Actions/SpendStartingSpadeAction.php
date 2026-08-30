<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TerrainType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SpendStartingSpadeAction
{
    public function execute(Game $game, User $user, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $user, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()
                ->whereKey($interaction?->playerId)
                ->whereBelongsTo($user)
                ->first();

            if (! in_array($lockedGame->phase, [GamePhase::Setup, GamePhase::Actions], true)
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::SpendSpades
                || isset($interaction->context['selectedHexId'])
                || ! $player instanceof GamePlayer
                || ! in_array($hexId, $interaction->optionIds, true)) {
                throw ValidationException::withMessages(['hex_id' => 'Эта клетка недоступна для преобразования.']);
            }

            $playerStateIndex = null;

            foreach ($state->players as $index => $playerState) {
                if ($playerState->playerId === $player->id) {
                    $playerStateIndex = $index;
                    break;
                }
            }

            if ($playerStateIndex === null || $state->players[$playerStateIndex]->unassignedSpades < 1) {
                throw ValidationException::withMessages(['game' => 'У игрока нет доступной лопаты.']);
            }

            $terrainBefore = null;
            $terrainAfter = null;
            $targetTerrain = TerrainType::tryFrom((string) ($interaction->context['targetTerrain'] ?? ''));

            if (! $targetTerrain instanceof TerrainType) {
                throw ValidationException::withMessages(['game' => 'Не определена целевая местность.']);
            }

            foreach ($state->board->hexes as $index => $hex) {
                if ($hex->id !== $hexId) {
                    continue;
                }

                if ($hex->building !== null
                    || ! $hex->terrain->isHomeland()
                    || $hex->terrain === $targetTerrain) {
                    throw ValidationException::withMessages(['hex_id' => 'Эту клетку нельзя преобразовать стартовой лопатой.']);
                }

                $terrainBefore = $hex->terrain;
                $terrainAfter = $terrainBefore->stepTowards($targetTerrain);
                $hex->terrain = $terrainAfter;
                $state->board->hexes[$index] = $hex;
                break;
            }

            if (! $terrainBefore instanceof TerrainType || ! $terrainAfter instanceof TerrainType) {
                throw ValidationException::withMessages(['hex_id' => 'Клетка карты не найдена.']);
            }

            $interaction->context['selectedHexId'] = $hexId;
            $interaction->context['terrainBefore'] = $terrainBefore->value;
            $interaction->context['terrainAfter'] = $terrainAfter->value;
            $state->pendingInteraction = $interaction;

            $lockedGame->update(['state' => $state]);

            return $lockedGame->refresh();
        });
    }
}
