<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\BoardStateFactory;
use App\Domain\Game\Factories\GamePlayerStateFactory;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class ReplayGameHistoryAction
{
    /** @var list<GameActionType> */
    public const array SUPPORTED_ACTION_TYPES = [
        GameActionType::StartGame,
        GameActionType::ChoosePlanningBundle,
        GameActionType::ChooseStartingResources,
        GameActionType::PlaceStartingBuilding,
        GameActionType::UndoStartingBuilding,
        GameActionType::FinishStartingBuildingTurn,
        GameActionType::ChooseCompetency,
        GameActionType::SpendStartingSpade,
    ];

    public function __construct(
        private BoardStateFactory $boardStateFactory,
        private GamePlayerStateFactory $playerStateFactory,
        private GameSetupPoolFactory $setupPoolFactory,
        private GrantCompetencyAction $grantCompetency,
        private ResolveCompletedStartingSetupAction $resolveCompletedStartingSetup,
    ) {
    }

    /** @param Collection<int, GameAction> $actions */
    public function execute(Game $game, Collection $actions): Game
    {
        $mapVariant = $game->state->board->variant;

        $game->update([
            'status' => GameStatus::Lobby,
            'round' => 1,
            'phase' => GamePhase::Setup,
            'active_player_id' => null,
            'version' => 0,
            'state' => new GameStateData(board: $this->boardStateFactory->create($mapVariant)),
            'started_at' => null,
            'finished_at' => null,
        ]);
        $game->players()->update([
            'color' => null,
            'faction' => null,
            'homeland' => null,
            'result_place' => null,
            'final_score' => null,
        ]);
        $players = $game->players()->orderBy('seat')->get();

        foreach ($actions as $action) {
            if (! in_array($action->type, self::SUPPORTED_ACTION_TYPES, true)) {
                throw ValidationException::withMessages([
                    'history' => "Воспроизведение действия {$action->type->value} ещё не поддерживается.",
                ]);
            }

            match ($action->type) {
                GameActionType::StartGame => $this->replayStartGame($game, $players, $action),
                GameActionType::ChoosePlanningBundle => $this->replayPlanningBundle($game, $players, $action),
                GameActionType::ChooseStartingResources => $this->replayStartingResources($game, $players, $action),
                GameActionType::PlaceStartingBuilding => $this->replayPlaceStartingBuilding($game, $players, $action),
                GameActionType::UndoStartingBuilding => $this->replayUndoStartingBuilding($game, $action),
                GameActionType::FinishStartingBuildingTurn => $this->replayFinishStartingBuildingTurn($game, $players, $action),
                GameActionType::ChooseCompetency => $this->replayStartingCompetency($game, $players, $action),
                GameActionType::SpendStartingSpade => $this->replayStartingSpade($game, $players, $action),
                default => null,
            };

            $game->version = $action->state_version_after;
            $game->save();
        }

        return $game->refresh();
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayStartGame(Game $game, Collection $players, GameAction $action): void
    {
        $setupPool = $this->setupPoolFactory->createFromSeed(
            $players->count(),
            $game->random_seed,
            $game->state->board->variant,
        );
        $orderedPlayers = $players
            ->slice($setupPool->firstPlayerIndex)
            ->concat($players->take($setupPool->firstPlayerIndex))
            ->values();

        $game->fill([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
            'active_player_id' => $orderedPlayers->firstOrFail()->user_id,
            'started_at' => $action->created_at,
            'state' => new GameStateData(
                schemaVersion: 3,
                turnOrder: $orderedPlayers->pluck('id')->all(),
                board: $game->state->board,
                round: new RoundStateData(
                    number: 1,
                    phase: GamePhase::Setup,
                    scoringTileId: $setupPool->roundScoringTiles[0]->value,
                    additionalScoringTileId: $setupPool->additionalFinalRoundGoal->value,
                ),
                availableTownTileIds: $this->enumValues($setupPool->townTiles),
                availablePalaceIds: $this->enumValues($setupPool->palaces),
                availableInventionIds: $this->enumValues($setupPool->innovations),
                availableCompetencyIds: $this->enumValues($setupPool->competencies),
                roundBonusIds: [
                    ...array_map(
                        static fn (PlanningBundleData $bundle): string => $bundle->roundBonus->value,
                        $setupPool->planningBundles,
                    ),
                    ...array_map(
                        static fn (RoundBonusOfferData $offer): string => $offer->roundBonus->value,
                        $setupPool->availableRoundBonuses,
                    ),
                ],
                setupPool: $setupPool,
            ),
        ]);
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayPlanningBundle(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);
        $homeland = TerrainType::from((string) $action->payload['homeland']);
        $state = $game->state;
        $bundle = collect($state->setupPool?->planningBundles)->first(
            static fn (PlanningBundleData $candidate): bool => $candidate->homeland === $homeland,
        );

        if (! $player instanceof GamePlayer || ! $bundle instanceof PlanningBundleData) {
            $this->invalidHistory();
        }

        $playerState = $this->playerStateFactory->create($player, $bundle);
        $player->update([
            'color' => $playerState->color,
            'faction' => $bundle->faction,
            'homeland' => $bundle->homeland,
        ]);
        $state->planningSelections[] = new PlayerPlanningSelectionData($player->id, $bundle);
        $state->players[] = $playerState;
        $requiresChoice = $playerState->resources->books->unassigned > 0
            || $playerState->knowledge->unassignedSteps > 0
            || $playerState->faction === Faction::Inventors;
        $state->pendingInteraction = $requiresChoice
            ? new PendingInteractionData(
                PendingInteractionType::ChooseStartingResources,
                $player->id,
                array_column(KnowledgeDiscipline::cases(), 'value'),
                [
                    'bookCount' => $playerState->resources->books->unassigned,
                    'knowledgeStepCount' => $playerState->knowledge->unassignedSteps,
                    'competencyIds' => $playerState->faction === Faction::Inventors
                        ? $this->enumValues($state->setupPool?->competencies ?? [])
                        : [],
                ],
            )
            : null;
        $game->state = $state;
        $game->active_player_id = $requiresChoice
            ? $player->user_id
            : $this->nextPlanningPlayer($game, $players, $player)->user_id;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayStartingResources(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $playerState = $this->playerState($state, $player->id);

        foreach ($action->payload['book_disciplines'] ?? [] as $disciplineValue) {
            $discipline = KnowledgeDiscipline::from((string) $disciplineValue);
            $playerState->resources->books->{$discipline->value}++;
        }

        foreach ($action->payload['knowledge_disciplines'] ?? [] as $disciplineValue) {
            $discipline = KnowledgeDiscipline::from((string) $disciplineValue);
            $playerState->knowledge->{$discipline->value}++;
        }

        $playerState->resources->books->unassigned = 0;
        $playerState->knowledge->unassignedSteps = 0;
        $competencyValue = $action->payload['competency'] ?? null;

        if (is_string($competencyValue)) {
            $this->grantCompetency->execute(
                $playerState,
                Competency::from($competencyValue),
                $state->setupPool?->competencies ?? [],
            );
        }

        $state->pendingInteraction = null;
        $game->state = $state;
        $game->active_player_id = $this->nextPlanningPlayer($game, $players, $player)->user_id;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayPlaceStartingBuilding(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);
        $hexId = (string) $action->payload['hex_id'];
        $state = $game->state;

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        foreach ($state->board->hexes as $index => $hex) {
            if ($hex->id === $hexId) {
                $buildingType = isset($action->payload['building_type'])
                    ? BuildingType::from((string) $action->payload['building_type'])
                    : ($player->faction === Faction::Monks ? BuildingType::University : BuildingType::Workshop);
                $hex->building = new BuildingStateData($buildingType, $player->id);
                $state->board->hexes[$index] = $hex;
                $state->pendingStartingBuildingHexId = $hexId;
                $game->state = $state;

                return;
            }
        }

        $this->invalidHistory();
    }

    private function replayUndoStartingBuilding(Game $game, GameAction $action): void
    {
        $state = $game->state;
        $hexId = (string) $action->payload['hex_id'];

        foreach ($state->board->hexes as $index => $hex) {
            if ($hex->id === $hexId) {
                $hex->building = null;
                $state->board->hexes[$index] = $hex;
                break;
            }
        }

        $state->pendingStartingBuildingHexId = null;
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayFinishStartingBuildingTurn(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $state->pendingStartingBuildingHexId = null;
        $state->startingBuildingTurnIndex++;
        $placementOrder = $this->startingBuildingOrder($state, $players);

        if ($player->faction === Faction::Monks) {
            $playerState = $this->playerState($state, $player->id);
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseCompetency,
                $player->id,
                array_values(array_filter(
                    $this->enumValues($state->setupPool?->competencies ?? []),
                    static fn (string $competencyId): bool => ! in_array(
                        $competencyId,
                        $playerState->competencyIds,
                        true,
                    ),
                )),
            );
            $game->active_player_id = $player->user_id;
        } elseif ($state->startingBuildingTurnIndex >= count($placementOrder)) {
            [$nextPlayer, $nextPhase] = $this->resolveCompletedStartingSetup->execute($state, $players);
            $game->phase = $nextPhase;
            $game->active_player_id = $nextPlayer->user_id;
        } else {
            $game->active_player_id = $players->firstWhere(
                'id',
                $placementOrder[$state->startingBuildingTurnIndex],
            )?->user_id;
        }

        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayStartingCompetency(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $this->grantCompetency->execute(
            $this->playerState($state, $player->id),
            Competency::from((string) $action->payload['competency_id']),
            $state->setupPool?->competencies ?? [],
        );
        $state->pendingInteraction = null;
        $placementOrder = $this->startingBuildingOrder($state, $players);

        if ($state->startingBuildingTurnIndex >= count($placementOrder)) {
            [$nextPlayer, $nextPhase] = $this->resolveCompletedStartingSetup->execute($state, $players);
            $game->phase = $nextPhase;
            $game->active_player_id = $nextPlayer->user_id;
        } else {
            $game->active_player_id = $players->firstWhere(
                'id',
                $placementOrder[$state->startingBuildingTurnIndex],
            )?->user_id;
        }

        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayStartingSpade(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;

        foreach ($state->board->hexes as $index => $hex) {
            if ($hex->id === (string) $action->payload['hex_id']) {
                $hex->terrain = TerrainType::from((string) $action->payload['terrain_after']);
                $state->board->hexes[$index] = $hex;
                break;
            }
        }

        $this->playerState($state, $player->id)->unassignedSpades--;
        $remainingSpades = (int) ($action->payload['remaining_spades'] ?? 0);

        if ($remainingSpades > 0) {
            $targetTerrain = TerrainType::from((string) $action->payload['target_terrain']);
            $eligibleHexIds = $this->resolveCompletedStartingSetup->eligibleHexIds(
                $state,
                $player->id,
                $targetTerrain,
            );
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::SpendSpades,
                $player->id,
                $eligibleHexIds,
                [
                    'spadeCount' => 2,
                    'remainingSpades' => $remainingSpades,
                    'targetTerrain' => $targetTerrain->value,
                ],
            );

            if ($eligibleHexIds !== []) {
                $game->phase = GamePhase::Setup;
                $game->active_player_id = $player->user_id;
            } else {
                $this->completeStartingInteraction($game, $state, $players);
            }
        } else {
            $this->completeStartingInteraction($game, $state, $players);
        }

        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function completeStartingInteraction(Game $game, GameStateData $state, Collection $players): void
    {
        $state->pendingInteraction = null;
        [$nextPlayer, $nextPhase] = $this->resolveCompletedStartingSetup->execute($state, $players);
        $game->phase = $nextPhase;
        $game->active_player_id = $nextPlayer->user_id;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function nextPlanningPlayer(Game $game, Collection $players, GamePlayer $currentPlayer): GamePlayer
    {
        $turnOrder = $game->state->turnOrder;
        $currentIndex = array_search($currentPlayer->id, $turnOrder, true);

        foreach (range(1, count($turnOrder)) as $offset) {
            $candidate = $players->firstWhere('id', $turnOrder[($currentIndex + $offset) % count($turnOrder)]);

            if ($candidate instanceof GamePlayer && $candidate->faction === null) {
                return $candidate;
            }
        }

        $firstPlayerId = $this->startingBuildingOrder($game->state, $players)[0] ?? null;
        $firstPlayer = $players->firstWhere('id', $firstPlayerId);

        if (! $firstPlayer instanceof GamePlayer) {
            $this->invalidHistory();
        }

        return $firstPlayer;
    }

    /**
     * @param Collection<int, GamePlayer> $players
     * @return list<int>
     */
    private function startingBuildingOrder(GameStateData $state, Collection $players): array
    {
        $monkPlayerIds = array_values(array_filter(
            $state->turnOrder,
            static fn (int $playerId): bool => $players->firstWhere('id', $playerId)?->faction === Faction::Monks,
        ));
        $regularPlayerIds = array_values(array_diff($state->turnOrder, $monkPlayerIds));

        return [...$regularPlayerIds, ...array_reverse($regularPlayerIds), ...$monkPlayerIds];
    }

    private function playerState(GameStateData $state, int $playerId): GamePlayerStateData
    {
        $playerState = collect($state->players)->firstWhere('playerId', $playerId);

        if (! $playerState instanceof GamePlayerStateData) {
            $this->invalidHistory();
        }

        return $playerState;
    }

    /**
     * @param list<\BackedEnum|string> $values
     * @return list<string>
     */
    private function enumValues(array $values): array
    {
        return array_map(
            static fn (\BackedEnum|string $value): string => $value instanceof \BackedEnum
                ? (string) $value->value
                : $value,
            $values,
        );
    }

    private function invalidHistory(): never
    {
        throw ValidationException::withMessages([
            'history' => 'История партии повреждена и не может быть воспроизведена.',
        ]);
    }
}
