<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\GamePlayerStateData;
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

            $playerState = collect($state->players)->firstWhere('playerId', $interaction->playerId);

            if ($playerState instanceof GamePlayerStateData) {
                $paidTools = (int) ($interaction->context['paidTools'] ?? 0);
                $paidSpadeCount = (int) ($interaction->context['paidSpadeCount'] ?? 0);
                $tunnelVictoryPoints = (int) ($interaction->context['tunnelVictoryPoints'] ?? 0);
                $flightScholarCost = (int) ($interaction->context['flightScholarCost'] ?? 0);
                $flightVictoryPoints = (int) ($interaction->context['flightVictoryPoints'] ?? 0);
                $playerState->resources->tools += $paidTools;
                $playerState->resources->scholars += $flightScholarCost;
                $playerState->unassignedSpades -= $paidSpadeCount;
                $playerState->victoryPoints -= $tunnelVictoryPoints;
                $playerState->victoryPoints -= $flightVictoryPoints;
                $interaction->context['remainingSpades'] = max(
                    0,
                    (int) ($interaction->context['remainingSpades'] ?? 0) - $paidSpadeCount,
                );
            }

            $interaction->optionIds = $interaction->context['optionIdsBeforeSelection'] ?? $interaction->optionIds;

            unset(
                $interaction->context['selectedHexId'],
                $interaction->context['terrainBefore'],
                $interaction->context['terrainAfter'],
                $interaction->context['spentSpades'],
                $interaction->context['paidTools'],
                $interaction->context['paidSpadeCount'],
                $interaction->context['spadesToSpend'],
                $interaction->context['tunnelTools'],
                $interaction->context['tunnelVictoryPoints'],
                $interaction->context['flightScholarCost'],
                $interaction->context['flightVictoryPoints'],
                $interaction->context['optionIdsBeforeSelection'],
            );
            $state->pendingInteraction = $interaction;
            $lockedGame->update(['state' => $state]);

            return $lockedGame->refresh();
        });
    }
}
