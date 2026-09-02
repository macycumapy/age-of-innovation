<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class BuildWorkshopAction
{
    public function __construct(
        private FindReachableLandHexesAction $findReachableLandHexes,
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
        private CreatePowerOffersAfterBuildingAction $createPowerOffersAfterBuilding,
        private AppendGameHistoryAction $appendGameHistory,
    ) {
    }

    public function execute(Game $game, User $user, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $user, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $hex = collect($state->board->hexes)->firstWhere('id', $hexId);

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || $state->round->hasTakenMainAction
                || ! $player instanceof GamePlayer
                || ! $hex instanceof BoardHexStateData) {
                throw ValidationException::withMessages(['building' => 'Сейчас нельзя построить дом.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            $workshopsOnMap = count(array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $candidate): bool => $candidate->building?->ownerPlayerId === $player->id
                    && $candidate->building->type === BuildingType::Workshop,
            ));

            if (! $playerState instanceof GamePlayerStateData
                || ! in_array($hexId, $this->findReachableLandHexes->execute($state, $playerState), true)
                || $hex->building !== null
                || $hex->terrain !== $playerState->homeland
                || $playerState->resources->tools < 1
                || $playerState->resources->coins < 2
                || $workshopsOnMap >= BuildingType::Workshop->supplyLimit()) {
                throw ValidationException::withMessages(['building' => 'Дом нельзя построить на выбранной клетке.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $playerState->resources->tools--;
            $playerState->resources->coins -= 2;
            $hex->building = new BuildingStateData(BuildingType::Workshop, $player->id);
            $state->round->hasTakenMainAction = true;
            $bonuses = $this->applyBuildingBonuses->execute($state, $playerState, $hex, BuildingType::Workshop);
            $nextActiveUserId = $this->createPowerOffersAfterBuilding->execute($state, $player->id, $hexId);
            $lockedGame->update([
                'active_player_id' => $nextActiveUserId ?? $player->user_id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::BuildWorkshop,
                [
                    'hex_id' => $hexId,
                    'tools' => 1,
                    'coins' => 2,
                    'victory_points' => $bonuses['victoryPoints'],
                    'bonus_coins' => $bonuses['coins'],
                    'scoring_sources' => $bonuses['sources'],
                ],
                [['type' => 'workshop_built', 'player_id' => $player->id, 'hex_id' => $hexId]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
