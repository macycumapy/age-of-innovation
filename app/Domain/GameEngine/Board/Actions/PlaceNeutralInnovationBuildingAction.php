<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Board\Actions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Enums\GameEventType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Setup\Actions\DetermineStartingBuildingOrderAction;
use App\Domain\GameEngine\Setup\Actions\ResolveCompletedStartingSetupAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
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

    public function execute(Game $game, GamePlayer $player, string $hexId): Game
    {
        return DB::transaction(function () use ($game, $player, $hexId): Game {
            $lockedGame = Game::query()->lockForUpdate()->findOrFail($game->id);
            $state = $lockedGame->state;
            $interaction = $state->pendingInteraction;
            $playerState = collect($state->players)->firstWhere('playerId', $player->id);
            $buildingType = BuildingType::tryFrom((string) ($interaction?->context['buildingType'] ?? ''));
            $hex = collect($state->board->hexes)->firstWhere('id', $hexId);

            $isStartingCompetency = $lockedGame->phase === GamePhase::Setup
                && ($interaction?->context['reason'] ?? null) === 'starting_competency';

            if ((! $isStartingCompetency && ! $lockedGame->phase->isActionPhase())
                || $player->game_id !== $lockedGame->id
                || ! $lockedGame->isActivePlayer($player)
                || $interaction?->type !== PendingInteractionType::PlaceNeutralBuilding
                || $interaction->playerId !== $player->id
                || ! $playerState instanceof GamePlayerStateData
                || ! $hex instanceof BoardHexStateData
                || $buildingType === null
                || ! in_array($hexId, $this->findEligibleHexes->execute($state, $playerState), true)) {
                throw ValidationException::withMessages(['hex_id' => 'На этой клетке нельзя поставить нейтральное здание.']);
            }

            if (! $isStartingCompetency) {
                $result = $this->applyPlaceNeutralBuilding->execute($state, $playerState, $hexId, $buildingType);
                $lockedGame->update([
                    'active_game_player_id' => $result->nextActivePlayerId,
                    'state' => $state,
                    'version' => $lockedGame->version + 1,
                ]);

                $this->updateSourceAction(
                    $lockedGame,
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
                $resolution = $this->resolveCompletedStartingSetup->execute($state);
                $nextPlayer = $lockedGame->players()->findOrFail($resolution->nextActivePlayerId);
                $nextPhase = $resolution->phase;
                $incomeReceipts = $resolution->incomeReceipts;
            } else {
                $nextPlayer = $lockedGame->players()
                    ->whereKey($placementOrder[$state->startingBuildingTurnIndex])
                    ->firstOrFail();
            }

            $nextActivePlayerId = $nextPlayer->id;
            $lockedGame->update([
                'phase' => $nextPhase,
                'active_game_player_id' => $nextActivePlayerId,
                'state' => $state,
                'version' => $lockedGame->version + 1,
            ]);

            $this->updateSourceAction(
                $lockedGame,
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
     * @param list<IncomeReceiptData> $incomeReceipts
     */
    private function updateSourceAction(
        Game $game,
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
            ->where('game_player_id', $player->id)
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
        $payload['income_receipts'] = array_map(
            static fn (IncomeReceiptData $receipt): array => $receipt->toArray(),
            $incomeReceipts,
        );
        $events = $sourceAction->events ?? [];
        $events[] = ['type' => GameEventType::NeutralBuildingBuilt->value, 'player_id' => $player->id, 'hex_id' => $hexId];
        $sourceAction->update([
            'payload' => $payload,
            'events' => $events,
            'state_version_after' => $game->version,
        ]);
    }
}
