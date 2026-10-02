<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Actions;

use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class FinishStartingBuildingTurnAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private ApplyStartingBuildingAction $applyStartingBuilding,
    ) {
    }

    public function execute(Game $game, GamePlayer $player, ?string $hexId = null): Game
    {
        return DB::transaction(function () use ($game, $player, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $stateVersionBefore = $lockedGame->version;
            $confirmedHexId = $hexId ?? $state->pendingStartingBuildingHexId;

            if ($lockedGame->phase !== GamePhase::Setup
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || ! is_string($confirmedHexId)) {
                throw ValidationException::withMessages(['game' => 'Сначала установите стартовый дом.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['game' => 'Не найдено состояние игрока.']);
            }
            $result = $this->applyStartingBuilding->execute($state, $playerState, $confirmedHexId);

            $lockedGame->update([
                'phase' => $result->nextPhase,
                'active_game_player_id' => $result->nextActivePlayerId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $player,
                GameActionType::PlaceStartingBuilding,
                [
                    'hex_id' => $confirmedHexId,
                    'building_type' => $result->buildingType->value,
                    'confirmed' => true,
                    'income_started' => $result->nextPhase !== GamePhase::Setup,
                    'round' => $state->round->number,
                    'income_receipts' => $result->incomeReceipts,
                ],
                [
                    [
                        'type' => GameEventType::StartingBuildingPlaced->value,
                        'player_id' => $player->id,
                        'hex_id' => $confirmedHexId,
                        'building_type' => $result->buildingType->value,
                    ],
                    ...($result->nextPhase !== GamePhase::Setup ? [[
                        'type' => GameEventType::IncomePhaseStarted->value,
                        'round' => $state->round->number,
                    ]] : []),
                ],
                $stateVersionBefore,
                $lockedGame->version,
                $result->nextPhase !== GamePhase::Setup,
            );

            return $lockedGame->refresh();
        });
    }
}
