<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinishStartingBuildingTurnAction
{
    public function __construct(private AppendGameHistoryAction $appendGameHistory)
    {
    }

    public function execute(Game $game, User $user): Game
    {
        return DB::transaction(function () use ($game, $user): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $stateVersionBefore = $lockedGame->version;

            if ($lockedGame->phase !== GamePhase::Setup || $lockedGame->active_player_id !== $user->id
                || $state->pendingStartingBuildingHexId === null) {
                throw ValidationException::withMessages(['game' => 'Сначала установите стартовый дом.']);
            }

            $confirmedHexId = $state->pendingStartingBuildingHexId;
            $state->pendingStartingBuildingHexId = null;
            $state->startingBuildingTurnIndex++;
            $placementOrder = [...$state->turnOrder, ...array_reverse($state->turnOrder)];

            if ($state->startingBuildingTurnIndex >= count($placementOrder)) {
                $nextPlayer = $lockedGame->players()->whereKey($state->turnOrder[0])->firstOrFail();
                $state->round->phase = GamePhase::Income;
                $nextPhase = GamePhase::Income;
            } else {
                $nextPlayer = $lockedGame->players()->whereKey($placementOrder[$state->startingBuildingTurnIndex])->first();
                $nextPhase = GamePhase::Setup;

                if (! $nextPlayer instanceof GamePlayer) {
                    throw ValidationException::withMessages(['game' => 'Нарушен порядок стартового выставления.']);
                }
            }

            $lockedGame->update([
                'phase' => $nextPhase,
                'active_player_id' => $nextPlayer->user_id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::FinishStartingBuildingTurn,
                ['hex_id' => $confirmedHexId],
                [[
                    'type' => 'starting_building_turn_finished',
                    'hex_id' => $confirmedHexId,
                    'turn_index' => $state->startingBuildingTurnIndex,
                    'next_player_id' => $nextPlayer->id,
                    'next_phase' => $nextPhase->value,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
