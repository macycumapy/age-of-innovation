<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class SpendStartingSpadeAction
{
    public function execute(Game $game, GamePlayer $player, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $player, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;

            if (! in_array($lockedGame->phase, [GamePhase::Setup, GamePhase::Actions, GamePhase::ScienceBonus], true)
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== PendingInteractionType::SpendSpades
                || $interaction->playerId !== $player->id
                || isset($interaction->context['selectedHexId'])
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

            $spadesToSpend = max(1, (int) ($interaction->context['spadesToSpend'] ?? 1));

            if ($playerStateIndex === null || $state->players[$playerStateIndex]->unassignedSpades < $spadesToSpend) {
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
                $terrainAfter = $terrainBefore;

                for ($step = 0; $step < $spadesToSpend; $step++) {
                    if ($terrainAfter === $targetTerrain) {
                        break;
                    }

                    $terrainAfter = $terrainAfter->stepTowards($targetTerrain);
                }
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
            $interaction->context['spentSpades'] = $spadesToSpend;
            $state->pendingInteraction = $interaction;

            $lockedGame->update(['state' => $state]);

            return $lockedGame->refresh();
        });
    }
}
