<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Models\Game;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlaceStartingBuildingAction
{
    public function __construct(private AppendGameHistoryAction $appendGameHistory)
    {
    }

    public function execute(Game $game, User $user, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $user, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $state = $lockedGame->state;
            $stateVersionBefore = $lockedGame->version;

            if ($lockedGame->status !== GameStatus::Active
                || $lockedGame->phase !== GamePhase::Setup
                || count($state->planningSelections) !== count($state->turnOrder)
                || $state->pendingInteraction !== null
                || $lockedGame->active_player_id !== $user->id
                || $player === null) {
                throw ValidationException::withMessages(['game' => 'Сейчас нельзя установить стартовый дом.']);
            }

            if ($state->pendingStartingBuildingHexId !== null) {
                throw ValidationException::withMessages(['hex_id' => 'Сначала отмените уже установленный дом или завершите ход.']);
            }

            foreach ($state->board->hexes as $index => $hex) {
                if ($hex->id !== $hexId) {
                    continue;
                }

                if ($hex->building !== null || $hex->terrain !== $player->homeland) {
                    throw ValidationException::withMessages(['hex_id' => 'Выберите свободную ячейку родной местности.']);
                }

                $buildingType = $player->faction === Faction::Monks
                    ? BuildingType::University
                    : BuildingType::Workshop;
                $hex->building = new BuildingStateData($buildingType, $player->id);
                $state->board->hexes[$index] = $hex;
                $state->pendingStartingBuildingHexId = $hexId;

                $lockedGame->update(['state' => $state, 'version' => $lockedGame->version + 1]);
                $this->appendGameHistory->execute(
                    $lockedGame,
                    $user,
                    GameActionType::PlaceStartingBuilding,
                    [
                        'hex_id' => $hexId,
                        'building_type' => $buildingType->value,
                    ],
                    [[
                        'type' => 'starting_building_placed',
                        'player_id' => $player->id,
                        'hex_id' => $hexId,
                        'building_type' => $buildingType->value,
                    ]],
                    $stateVersionBefore,
                    $lockedGame->version,
                );

                return $lockedGame->refresh();
            }

            throw ValidationException::withMessages(['hex_id' => 'Ячейка карты не найдена.']);
        });
    }
}
