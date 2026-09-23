<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameEventType;
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
        private ApplyPlaceNeutralBuildingAction $applyPlaceNeutralBuilding,
        private ApplyBuildingBonusesAction $applyBuildingBonuses,
        private DetermineStartingBuildingOrderAction $determineStartingBuildingOrder,
        private ResolveCompletedStartingSetupAction $resolveCompletedStartingSetup,
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

            $isStartingCompetency = $lockedGame->phase === GamePhase::Setup
                && ($interaction?->context['reason'] ?? null) === 'starting_competency';

            if ((! $isStartingCompetency && ! $lockedGame->phase->isActionPhase())
                || $lockedGame->active_player_id !== $user->id
                || $interaction?->type !== PendingInteractionType::PlaceNeutralBuilding
                || ! $playerState instanceof GamePlayerStateData
                || ! $hex instanceof BoardHexStateData
                || $buildingType === null
                || ! in_array($hexId, $this->findEligibleHexes->execute($state, $playerState), true)) {
                throw ValidationException::withMessages(['hex_id' => 'На этой клетке нельзя поставить нейтральное здание.']);
            }

            if (! $isStartingCompetency) {
                $result = $this->applyPlaceNeutralBuilding->execute($state, $playerState, $hexId, $buildingType);
                $lockedGame->update([
                    'active_player_id' => $result->nextActiveUserId,
                    'state' => $state,
                    'version' => $lockedGame->version + 1,
                ]);

                $this->updateSourceAction(
                    $lockedGame,
                    $user,
                    $player,
                    $interaction->context,
                    $hexId,
                    $buildingType,
                    $result->toolCost,
                    $result->victoryPoints,
                    $result->bonusCoins,
                    $result->scoringSources,
                );

                return $lockedGame->refresh();
            }

            $toolCost = $hex->terrain->spadesTo($playerState->homeland) * max(1, 3 - $playerState->terraformingLevel);
            $playerState->resources->tools -= $toolCost;
            $hex->terrain = $playerState->homeland;
            $hex->building = new BuildingStateData($buildingType, $playerState->playerId, isNeutral: true);
            $state->pendingInteraction = null;
            $bonuses = $this->applyBuildingBonuses->execute($state, $playerState, $hex, $buildingType);
            $nextPhase = $lockedGame->phase;
            $incomeReceipts = [];

            $placementOrder = $this->determineStartingBuildingOrder->execute($lockedGame);

            if ($state->startingBuildingTurnIndex >= count($placementOrder)) {
                [$nextPlayer, $nextPhase, $incomeReceipts] = $this->resolveCompletedStartingSetup->execute(
                    $state,
                    $lockedGame->players()->get(),
                );
            } else {
                $nextPlayer = $lockedGame->players()
                    ->whereKey($placementOrder[$state->startingBuildingTurnIndex])
                    ->firstOrFail();
            }

            $nextActiveUserId = $nextPlayer->user_id;
            $lockedGame->update([
                'phase' => $nextPhase,
                'active_player_id' => $nextActiveUserId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);

            $this->updateSourceAction(
                $lockedGame,
                $user,
                $player,
                $interaction->context,
                $hexId,
                $buildingType,
                $toolCost,
                $bonuses['victoryPoints'],
                $bonuses['coins'],
                $bonuses['sources'],
                $incomeReceipts,
            );

            return $lockedGame->refresh();
        });
    }

    /**
     * @param array<string, mixed> $context
     * @param list<array{source: string, id: string, points: int}> $scoringSources
     * @param list<array<string, mixed>> $incomeReceipts
     */
    private function updateSourceAction(
        Game $game,
        User $user,
        GamePlayer $player,
        array $context,
        string $hexId,
        BuildingType $buildingType,
        int $toolCost,
        int $victoryPoints,
        int $bonusCoins,
        array $scoringSources,
        array $incomeReceipts = [],
    ): void {
        $sourceActionType = ($context['source'] ?? null) === 'competency'
            ? GameActionType::ChooseCompetency
            : GameActionType::MakeInnovation;
        $sourceAction = $game->actions()
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
            'victory_points' => $victoryPoints,
            'bonus_coins' => $bonusCoins,
            'scoring_sources' => $scoringSources,
        ];
        $payload['income_receipts'] = $incomeReceipts;
        $events = $sourceAction->events ?? [];
        $events[] = ['type' => GameEventType::NeutralBuildingBuilt->value, 'player_id' => $player->id, 'hex_id' => $hexId];
        $sourceAction->update([
            'payload' => $payload,
            'events' => $events,
            'state_version_after' => $game->version,
        ]);
    }
}
