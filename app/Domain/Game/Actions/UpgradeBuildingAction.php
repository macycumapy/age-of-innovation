<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class UpgradeBuildingAction
{
    public function __construct(
        private AppendGameHistoryAction $appendGameHistory,
        private CreatePowerOffersAfterBuildingAction $createPowerOffersAfterBuilding,
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
    ) {
    }

    public function execute(Game $game, User $user, string $hexId, BuildingType $target): Game
    {
        return DB::transaction(function () use ($game, $user, $hexId, $target): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $player = $lockedGame->players()->whereBelongsTo($user)->first();
            $hex = collect($state->board->hexes)->firstWhere('id', $hexId);

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $state->pendingInteraction !== null
                || ! $player instanceof GamePlayer
                || ! $hex instanceof BoardHexStateData
                || $hex->building === null
                || $hex->building->ownerPlayerId !== $player->id
                || $hex->building->isNeutral
                || ! in_array($target, $hex->building->type->upgradeOptions(), true)) {
                throw ValidationException::withMessages(['building' => 'Это здание нельзя улучшить выбранным способом.']);
            }

            $playerState = collect($state->players)->firstWhere('playerId', $player->id);

            if (! $playerState instanceof GamePlayerStateData) {
                throw ValidationException::withMessages(['building' => 'Не найдено состояние игрока.']);
            }

            $hasAdjacentOpponent = collect($state->board->hexes)->contains(
                static fn (BoardHexStateData $candidate): bool => in_array($candidate->id, $hex->adjacentHexIds, true)
                    && $candidate->building !== null
                    && $candidate->building->ownerPlayerId !== $player->id,
            );
            $cost = $hex->building->type->upgradeCostTo($target, $hasAdjacentOpponent);
            $targetBuildingsOnMap = count(array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $candidate): bool => $candidate->building?->ownerPlayerId === $player->id
                    && $candidate->building->type === $target
                    && ! $candidate->building->isNeutral,
            ));

            if ($playerState->resources->tools < $cost['tools']
                || $playerState->resources->coins < $cost['coins']
                || $targetBuildingsOnMap >= $target->supplyLimit()) {
                throw ValidationException::withMessages(['building' => 'Не хватает ресурсов или свободной фигурки здания.']);
            }

            $stateVersionBefore = $lockedGame->version;

            if ($state->turnStartSnapshot === null) {
                $state->turnStartSnapshot = $state->toArray();
                $state->round->turnStartVersion = $stateVersionBefore;
            }

            $source = $hex->building->type;
            $playerState->resources->tools -= $cost['tools'];
            $playerState->resources->coins -= $cost['coins'];
            $hex->building->type = $target;
            $bonuses = $this->applyBuildingBonuses->execute($state, $playerState, $hex, $target);
            $nextActiveUserId = $this->createPowerOffersAfterBuilding->execute($state, $player->id, $hexId);
            $lockedGame->update([
                'active_player_id' => $nextActiveUserId ?? $player->user_id,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);
            $this->appendGameHistory->execute(
                $lockedGame,
                $user,
                GameActionType::UpgradeBuilding,
                [
                    'hex_id' => $hexId,
                    'source' => $source->value,
                    'target' => $target->value,
                    'tools' => $cost['tools'],
                    'coins' => $cost['coins'],
                    'victory_points' => $bonuses['victoryPoints'],
                    'bonus_coins' => $bonuses['coins'],
                    'scoring_sources' => $bonuses['sources'],
                ],
                [[
                    'type' => 'building_upgraded',
                    'player_id' => $player->id,
                    'hex_id' => $hexId,
                    'source' => $source->value,
                    'target' => $target->value,
                ]],
                $stateVersionBefore,
                $lockedGame->version,
            );

            return $lockedGame->refresh();
        });
    }
}
