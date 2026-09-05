<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PlaceNeutralInnovationBuildingAction
{
    public function __construct(
        private FindEligibleNeutralBuildingHexesAction $findEligibleHexes,
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
        private CreateBuildingFollowUpInteractionAction $createBuildingFollowUpInteraction,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
    ) {
    }

    public function execute(Game $game, User $user, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $user, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $player = $lockedGame->players()->whereKey($interaction?->playerId)->whereBelongsTo($user)->first();
            $playerState = $player instanceof GamePlayer
                ? collect($state->players)->firstWhere('playerId', $player->id)
                : null;
            $buildingType = BuildingType::tryFrom((string) ($interaction?->context['buildingType'] ?? ''));
            $hex = collect($state->board->hexes)->firstWhere('id', $hexId);

            if ($lockedGame->phase !== GamePhase::Actions
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::PlaceNeutralBuilding
                || ! $playerState instanceof GamePlayerStateData
                || ! $hex instanceof BoardHexStateData
                || $buildingType === null
                || ! in_array($hexId, $this->findEligibleHexes->execute($state, $playerState), true)) {
                throw ValidationException::withMessages(['hex_id' => 'На этой клетке нельзя поставить нейтральное здание.']);
            }

            $toolCost = $hex->terrain->spadesTo($playerState->homeland) * max(1, 3 - $playerState->terraformingLevel);
            $playerState->resources->tools -= $toolCost;
            $hex->terrain = $playerState->homeland;
            $hex->building = new BuildingStateData($buildingType, $playerState->playerId, isNeutral: true);
            $state->pendingInteraction = null;
            $bonuses = $this->applyBuildingBonuses->execute($state, $playerState, $hex, $buildingType);
            $queuedBuiltHexIds = array_values(array_filter(
                (array) ($interaction->context['queuedBuiltHexIds'] ?? []),
                'is_string',
            ));
            $nextActiveUserId = $buildingType === BuildingType::Tower
                ? $this->createTownChoiceAfterBuilding->execute($state, $playerState, $hexId, $queuedBuiltHexIds)
                : $this->createBuildingFollowUpInteraction->execute($state, $playerState, $hexId, $buildingType);
            $lockedGame->update([
                'active_player_id' => $nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);

            $sourceActionType = ($interaction->context['source'] ?? null) === 'competency'
                ? GameActionType::ChooseCompetency
                : GameActionType::MakeInnovation;
            $sourceAction = $lockedGame->actions()
                ->where('type', $sourceActionType)
                ->where('player_id', $user->id)
                ->latest('sequence')
                ->first();

            if ($sourceAction === null) {
                throw ValidationException::withMessages(['game' => 'Не найден источник нейтрального здания.']);
            }

            $payload = $sourceAction->payload;
            $payload['neutral_building'] = [
                'hex_id' => $hexId,
                'type' => $buildingType->value,
                'tools' => $toolCost,
                'victory_points' => $bonuses['victoryPoints'],
                'bonus_coins' => $bonuses['coins'],
                'scoring_sources' => $bonuses['sources'],
            ];
            $events = $sourceAction->events ?? [];
            $events[] = ['type' => 'neutral_building_built', 'player_id' => $player->id, 'hex_id' => $hexId];
            $sourceAction->update([
                'payload' => $payload,
                'events' => $events,
                'state_version_after' => $lockedGame->version,
            ]);

            return $lockedGame->refresh();
        });
    }
}
