<?php

declare(strict_types=1);

namespace App\Domain\Game\Actions;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BridgeStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PowerAction;
use App\Domain\Game\Enums\ResourceExchange;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TownTile;
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
        GameActionType::ChooseIncomeResources,
        GameActionType::PlaceStartingBuilding,
        GameActionType::UndoStartingBuilding,
        GameActionType::FinishStartingBuildingTurn,
        GameActionType::ChooseCompetency,
        GameActionType::SpendStartingSpade,
        GameActionType::SacrificePower,
        GameActionType::ExchangeResources,
        GameActionType::PowerAction,
        GameActionType::BookAction,
        GameActionType::TerraformAndBuild,
        GameActionType::BuildWorkshop,
        GameActionType::FinishTurn,
        GameActionType::AcceptPower,
        GameActionType::DeclinePower,
        GameActionType::UpgradeBuilding,
        GameActionType::ChoosePalace,
        GameActionType::PlacePalaceGuild,
        GameActionType::SendScholar,
        GameActionType::SpecialAction,
        GameActionType::Pass,
        GameActionType::ChooseScienceBonusBooks,
        GameActionType::ChooseTown,
        GameActionType::ChooseTownBooks,
        GameActionType::AcceptPalaceWaterTown,
        GameActionType::DeclinePalaceWaterTown,
    ];

    public function __construct(
        private BoardStateFactory $boardStateFactory,
        private GamePlayerStateFactory $playerStateFactory,
        private GameSetupPoolFactory $setupPoolFactory,
        private GrantCompetencyAction $grantCompetency,
        private ApplyResourceExchangeAction $applyResourceExchange,
        private ApplyPowerActionAction $applyPowerAction,
        private ApplyBookActionAction $applyBookAction,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private CreateTownChoiceAfterBuildingAction $createTownChoiceAfterBuilding,
        private CreateBuildingFollowUpInteractionAction $createBuildingFollowUpInteraction,
        private ApplyPowerOfferDecisionAction $applyPowerOfferDecision,
        private AdvanceKnowledgeAction $advanceKnowledge,
        private ResolveCompletedStartingSetupAction $resolveCompletedStartingSetup,
        private ApplyRoundBonusAction $applyRoundBonusAction,
        private ApplyFactionAction $applyFactionAction,
        private ApplyPalaceAction $applyPalaceAction,
        private ApplyPassAction $applyPassAction,
        private ResolveScienceBonusPhaseAction $resolveScienceBonusPhase,
        private ResolveIncomePhaseAction $resolveIncomePhase,
        private GainPowerAction $gainPower,
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
                GameActionType::ChooseStartingResources,
                GameActionType::ChooseIncomeResources => $this->replayStartingResources($game, $players, $action),
                GameActionType::PlaceStartingBuilding => $this->replayPlaceStartingBuilding($game, $players, $action),
                GameActionType::UndoStartingBuilding => $this->replayUndoStartingBuilding($game, $action),
                GameActionType::FinishStartingBuildingTurn => $this->replayFinishStartingBuildingTurn($game, $players, $action),
                GameActionType::ChooseCompetency => $this->replayStartingCompetency($game, $players, $action),
                GameActionType::SpendStartingSpade => $this->replayStartingSpade($game, $players, $action),
                GameActionType::SacrificePower => $this->replaySacrificePower($game, $players, $action),
                GameActionType::ExchangeResources => $this->replayResourceExchange($game, $players, $action),
                GameActionType::PowerAction => $this->replayPowerAction($game, $players, $action),
                GameActionType::BookAction => $this->replayBookAction($game, $players, $action),
                GameActionType::TerraformAndBuild => $this->replayTerraformAndBuild($game, $players, $action),
                GameActionType::BuildWorkshop => $this->replayBuildWorkshop($game, $players, $action),
                GameActionType::FinishTurn => $this->replayFinishTurn($game, $players, $action),
                GameActionType::AcceptPower, GameActionType::DeclinePower => $this->replayPowerOfferDecision(
                    $game,
                    $players,
                    $action,
                ),
                GameActionType::UpgradeBuilding => $this->replayUpgradeBuilding($game, $players, $action),
                GameActionType::ChoosePalace => $this->replayChoosePalace($game, $players, $action),
                GameActionType::PlacePalaceGuild => $this->replayPlacePalaceGuild($game, $players, $action),
                GameActionType::SendScholar => $this->replaySendScholar($game, $players, $action),
                GameActionType::SpecialAction => $this->replayRoundBonusAction($game, $players, $action),
                GameActionType::Pass => $this->replayPass($game, $players, $action),
                GameActionType::ChooseScienceBonusBooks => $this->replayScienceBonusBooks($game, $players, $action),
                GameActionType::ChooseTown => $this->replayChooseTown($game, $players, $action),
                GameActionType::ChooseTownBooks => $this->replayChooseTownBooks($game, $players, $action),
                GameActionType::AcceptPalaceWaterTown, GameActionType::DeclinePalaceWaterTown => $this->replayPalaceWaterTownDecision($game, $players, $action),
                default => null,
            };

            $game->version = $action->state_version_after;
            $game->save();
        }

        return $game->refresh();
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayRoundBonusAction(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $disciplineValue = $action->payload['discipline'] ?? null;
        $discipline = is_string($disciplineValue) ? KnowledgeDiscipline::from($disciplineValue) : null;
        $playerState = $this->playerState($state, $player->id);

        if (isset($action->payload['palace'])) {
            $result = $this->applyPalaceAction->execute(
                $state,
                $playerState,
                $discipline,
                is_string($action->payload['hex_id'] ?? null) ? $action->payload['hex_id'] : null,
            );
            $game->active_player_id = $result['nextActiveUserId'];
        } elseif (isset($action->payload['faction'])) {
            $this->applyFactionAction->execute($state, $playerState, $discipline);
        } else {
            $this->applyRoundBonusAction->execute($state, $playerState, $discipline);
        }

        $this->applyReplayedBridge($state, $player->id, $action);

        $game->state = $state;
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
                availableTownTileIds: array_merge(...array_fill(
                    0,
                    3,
                    $this->enumValues($setupPool->townTiles),
                )),
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

        if (($action->payload['phase'] ?? GamePhase::Setup->value) === GamePhase::Income->value) {
            [$nextPlayer, $nextPhase] = $this->resolveIncomePhase->execute($state, $players);
            $game->phase = $nextPhase;
            $game->active_player_id = $nextPlayer->user_id;

            return;
        }

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
                    : $this->startingBuildingType($state, $player);
                $hex->building = new BuildingStateData(
                    $buildingType,
                    $player->id,
                    isNeutral: $buildingType === BuildingType::Tower,
                );
                $state->board->hexes[$index] = $hex;
                $state->pendingStartingBuildingHexId = $hexId;
                $game->state = $state;

                if (($action->payload['confirmed'] ?? false) === true) {
                    $this->replayFinishStartingBuildingTurn($game, $players, $action);
                }

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
        $isBuildingChoice = ($action->payload['reason'] ?? null) === 'building';
        $this->grantCompetency->execute(
            $this->playerState($state, $player->id),
            Competency::from((string) $action->payload['competency_id']),
            $isBuildingChoice
                ? $state->availableCompetencyIds
                : ($state->setupPool?->competencies ?? []),
        );
        $state->pendingInteraction = null;

        if ($isBuildingChoice) {
            $nextActiveUserId = $this->createTownChoiceAfterBuilding->execute(
                $state,
                $this->playerState($state, $player->id),
                (string) ($action->payload['built_hex_id'] ?? ''),
            );
            $game->active_player_id = $nextActiveUserId;
            $game->state = $state;

            return;
        }

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

        $playerState = $this->playerState($state, $player->id);
        $playerState->resources->tools -= (int) ($action->payload['paid_tools'] ?? 0);
        $playerState->unassignedSpades += (int) ($action->payload['paid_spade_count'] ?? 0);
        $playerState->unassignedSpades -= (int) ($action->payload['spades_spent'] ?? 1);
        $remainingSpades = (int) ($action->payload['remaining_spades'] ?? 0);
        $interactionPhase = GamePhase::tryFrom((string) ($action->payload['phase'] ?? ''))
            ?? GamePhase::Setup;

        if ($remainingSpades > 0) {
            $targetTerrain = TerrainType::from((string) $action->payload['target_terrain']);
            $eligibleHexIds = $this->findEligibleTerraformHexes->execute(
                $state,
                $this->playerState($state, $player->id),
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
                    'phase' => $interactionPhase->value,
                    'buildableHexIds' => $action->payload['buildable_hex_ids'] ?? [],
                ],
            );

            if ($eligibleHexIds !== []) {
                $game->phase = $interactionPhase;
                $game->active_player_id = $player->user_id;
            } elseif ($interactionPhase === GamePhase::Actions) {
                $state->pendingInteraction = null;
            } elseif ($interactionPhase === GamePhase::ScienceBonus) {
                $state->pendingInteraction = null;
                [$nextPlayer, $nextPhase] = $this->resolveScienceBonusPhase->execute($state, $players);
                $game->phase = $nextPhase;
                $game->active_player_id = $nextPlayer?->user_id;
            } else {
                $this->completeStartingInteraction($game, $state, $players);
            }
        } elseif ($interactionPhase === GamePhase::Actions) {
            $buildableHexIds = $action->payload['buildable_hex_ids'] ?? [];
            $playerState = $this->playerState($state, $player->id);
            $availableHexIds = array_values(array_filter(
                $buildableHexIds,
                static fn (string $hexId): bool => collect($state->board->hexes)->contains(
                    static fn (BoardHexStateData $hex): bool => $hex->id === $hexId
                        && $hex->terrain === $playerState->homeland
                        && $hex->building === null,
                ),
            ));
            $state->pendingInteraction = $availableHexIds === []
                || ! (bool) ($action->payload['build_offered'] ?? false)
                    ? null
                    : new PendingInteractionData(
                        PendingInteractionType::BuildWorkshopAfterTerraforming,
                        $player->id,
                        $availableHexIds,
                        ['toolCost' => 1, 'coinCost' => 2],
                    );
        } elseif ($interactionPhase === GamePhase::ScienceBonus) {
            $state->pendingInteraction = null;
            [$nextPlayer, $nextPhase] = $this->resolveScienceBonusPhase->execute($state, $players);
            $game->phase = $nextPhase;
            $game->active_player_id = $nextPlayer?->user_id;
        } else {
            $this->completeStartingInteraction($game, $state, $players);
        }

        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayTerraformAndBuild(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $state->round->hasTakenMainAction = true;

        if ((bool) ($action->payload['built'] ?? false)) {
            $hex = collect($state->board->hexes)->firstWhere('id', $action->payload['hex_id'] ?? null);
            $playerState = $this->playerState($state, $player->id);

            if (! $hex instanceof BoardHexStateData) {
                $this->invalidHistory();
            }

            $playerState->resources->tools--;
            $playerState->resources->coins -= 2;
            $playerState->resources->coins += (int) ($action->payload['bonus_coins'] ?? 0);
            $playerState->victoryPoints += (int) ($action->payload['victory_points'] ?? 0);
            $hex->building = new BuildingStateData(BuildingType::Workshop, $player->id);
            $nextActiveUserId = $this->createTownChoiceAfterBuilding->execute(
                $state,
                $playerState,
                $hex->id,
            );
            $game->active_player_id = $nextActiveUserId;
        } else {
            $state->pendingInteraction = null;
        }

        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayBuildWorkshop(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);
        $hex = collect($game->state->board->hexes)->firstWhere('id', $action->payload['hex_id'] ?? null);

        if (! $player instanceof GamePlayer || ! $hex instanceof BoardHexStateData || $hex->building !== null) {
            $this->invalidHistory();
        }

        $state = $game->state;

        if ($state->turnStartSnapshot === null) {
            $state->turnStartSnapshot = $state->toArray();
            $state->round->turnStartVersion = $game->version;
        }

        $playerState = $this->playerState($state, $player->id);
        $playerState->resources->tools -= (int) $action->payload['tools'];
        $playerState->resources->coins -= (int) $action->payload['coins'];
        $playerState->resources->coins += (int) ($action->payload['bonus_coins'] ?? 0);
        $playerState->victoryPoints += (int) ($action->payload['victory_points'] ?? 0);
        $hex->building = new BuildingStateData(BuildingType::Workshop, $player->id);
        $state->round->hasTakenMainAction = true;
        $game->active_player_id = $this->createBuildingFollowUpInteraction->execute(
            $state,
            $playerState,
            $hex->id,
            BuildingType::Workshop,
        );
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayChooseTown(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);
        $townTile = TownTile::tryFrom((string) ($action->payload['town_tile'] ?? ''));

        if (! $player instanceof GamePlayer || $townTile === null) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $playerState = $this->playerState($state, $player->id);
        $townHexIds = $action->payload['town_hex_ids'] ?? [];
        $markerHexId = (string) ($action->payload['marker_hex_id'] ?? '');
        $queuedBuiltHexIds = is_array($action->payload['queued_built_hex_ids'] ?? null)
            ? $action->payload['queued_built_hex_ids']
            : [];

        foreach ($state->board->hexes as $hex) {
            if (is_array($townHexIds) && in_array($hex->id, $townHexIds, true)) {
                $hex->townId = (string) $action->payload['town_id'];
                $hex->townTileId = $hex->id === $markerHexId ? $townTile->value : null;
            }
        }

        $playerState->townTileIds[] = $townTile->value;
        $playerState->victoryPoints += (int) ($action->payload['victory_points'] ?? 0);
        $townTileIndex = array_search($townTile->value, $state->availableTownTileIds, true);

        if ($townTileIndex !== false) {
            array_splice($state->availableTownTileIds, $townTileIndex, 1);
        }

        match ($townTile) {
            TownTile::Tools => $playerState->resources->tools += 3,
            TownTile::Books => $playerState->resources->books->unassigned += 2,
            TownTile::Coins => $playerState->resources->coins += 6,
            TownTile::Knowledge => array_map(
                fn (KnowledgeDiscipline $discipline) => $this->advanceKnowledge->execute($playerState, $discipline, 1),
                KnowledgeDiscipline::cases(),
            ),
            TownTile::Power => $this->gainPower->execute($playerState, 8),
            TownTile::Scholar => $playerState->resources->scholars++,
            TownTile::Terraform => $playerState->unassignedSpades += 2,
        };
        if ($townTile === TownTile::Terraform) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::SpendSpades,
                $player->id,
                $this->findEligibleTerraformHexes->execute($state, $playerState, $playerState->homeland),
                [
                    'phase' => GamePhase::Actions->value,
                    'spadeCount' => 2,
                    'remainingSpades' => 2,
                    'targetTerrain' => $playerState->homeland->value,
                ],
            );
            $game->active_player_id = $player->user_id;
        } elseif ($townTile === TownTile::Books) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseTownBooks,
                $player->id,
                [],
                [
                    'bookCount' => 2,
                    'builtHexId' => $markerHexId,
                    'queuedBuiltHexIds' => $queuedBuiltHexIds,
                ],
            );
            $game->active_player_id = $player->user_id;
        } else {
            $state->pendingInteraction = null;
            $game->active_player_id = $player->user_id;
        }

        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayChooseTownBooks(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer
            || $game->state->pendingInteraction?->type !== PendingInteractionType::ChooseTownBooks) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $interaction = $state->pendingInteraction;
        $playerState = $this->playerState($state, $player->id);

        foreach (($action->payload['disciplines'] ?? []) as $disciplineValue) {
            $discipline = KnowledgeDiscipline::tryFrom((string) $disciplineValue);

            if ($discipline === null) {
                $this->invalidHistory();
            }

            $playerState->resources->books->{$discipline->value}++;
            $playerState->resources->books->unassigned--;
        }

        $state->pendingInteraction = null;
        $game->active_player_id = $player->user_id;
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayPalaceWaterTownDecision(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer
            || $game->state->pendingInteraction?->type !== PendingInteractionType::OfferPalaceWaterTown) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $builtHexId = (string) ($action->payload['built_hex_id'] ?? '');
        $queuedBuiltHexIds = is_array($action->payload['queued_built_hex_ids'] ?? null)
            ? $action->payload['queued_built_hex_ids']
            : [];

        if ($action->type === GameActionType::AcceptPalaceWaterTown) {
            $waterHexId = (string) ($action->payload['water_hex_id'] ?? '');
            $townHexIds = is_array($action->payload['town_hex_ids'] ?? null)
                ? $action->payload['town_hex_ids']
                : [];
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::ChooseTown,
                $player->id,
                array_values(array_unique($state->availableTownTileIds)),
                [
                    'townHexIds' => [...$townHexIds, $waterHexId],
                    'builtHexId' => $builtHexId,
                    'markerHexId' => $waterHexId,
                    'queuedBuiltHexIds' => $queuedBuiltHexIds,
                ],
            );
            $game->active_player_id = $player->user_id;
        } else {
            $state->pendingInteraction = null;
            $game->active_player_id = $player->user_id;
        }

        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayChoosePalace(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer
            || $game->state->pendingInteraction?->type !== PendingInteractionType::ChoosePalace) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $playerState = $this->playerState($state, $player->id);
        $palace = PalaceAbility::tryFrom((string) ($action->payload['palace_id'] ?? ''));

        if ($palace === null || ! in_array($palace->value, $state->availablePalaceIds, true)) {
            $this->invalidHistory();
        }

        $playerState->palaceId = $palace->value;
        $playerState->victoryPoints += (int) ($action->payload['victory_points'] ?? 0);
        $state->availablePalaceIds = array_values(array_filter(
            $state->availablePalaceIds,
            static fn (string $palaceId): bool => $palaceId !== $palace->value,
        ));
        $builtHexId = (string) ($action->payload['built_hex_id'] ?? '');
        $state->pendingInteraction = null;

        if ($palace === PalaceAbility::Palace16) {
            $state->pendingInteraction = new PendingInteractionData(
                PendingInteractionType::PlacePalaceGuild,
                $player->id,
                array_values(array_map(
                    static fn (BoardHexStateData $hex): string => $hex->id,
                    array_filter(
                        $state->board->hexes,
                        static fn (BoardHexStateData $hex): bool => $hex->terrain === $playerState->homeland
                            && $hex->building === null,
                    ),
                )),
                ['palaceBuiltHexId' => $builtHexId, 'selectedHexId' => null],
            );
            $game->active_player_id = $player->user_id;
        } else {
            $game->active_player_id = $this->createTownChoiceAfterBuilding->execute(
                $state,
                $playerState,
                $builtHexId,
            );
        }
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayPlacePalaceGuild(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);
        $hex = collect($game->state->board->hexes)->firstWhere('id', $action->payload['hex_id'] ?? null);

        if (! $player instanceof GamePlayer
            || ! $hex instanceof BoardHexStateData
            || $game->state->pendingInteraction?->type !== PendingInteractionType::PlacePalaceGuild) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $playerState = $this->playerState($state, $player->id);
        $hex->building = new BuildingStateData(BuildingType::Guild, $player->id);
        $playerState->resources->coins += (int) ($action->payload['bonus_coins'] ?? 0);
        $playerState->victoryPoints += (int) ($action->payload['victory_points'] ?? 0);
        $state->pendingInteraction = null;
        $nextActiveUserId = $this->createTownChoiceAfterBuilding->execute(
            $state,
            $playerState,
            $hex->id,
            [(string) ($action->payload['palace_built_hex_id'] ?? '')],
        );
        $game->active_player_id = $nextActiveUserId;
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replaySendScholar(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);
        $discipline = KnowledgeDiscipline::tryFrom((string) ($action->payload['discipline'] ?? ''));

        if (! $player instanceof GamePlayer || $discipline === null) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $playerState = $this->playerState($state, $player->id);

        if ($state->turnStartSnapshot === null) {
            $state->turnStartSnapshot = $state->toArray();
            $state->round->turnStartVersion = $game->version;
        }

        $playerState->resources->scholars--;

        if ((bool) ($action->payload['placed'] ?? false)) {
            $playerState->scholarPoolSize--;
            $playerState->scholarDisciplineIds[] = $discipline->value;
        }

        $this->advanceKnowledge->execute($playerState, $discipline, (int) ($action->payload['steps'] ?? 0));
        $playerState->victoryPoints += (int) ($action->payload['victory_points'] ?? 0);
        $state->round->hasTakenMainAction = true;
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayPowerOfferDecision(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $result = $this->applyPowerOfferDecision->execute(
            $state,
            $player->id,
            $action->type === GameActionType::AcceptPower,
        );
        $game->active_player_id = $result['nextActiveUserId'];
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayUpgradeBuilding(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);
        $hex = collect($game->state->board->hexes)->firstWhere('id', $action->payload['hex_id'] ?? null);

        if (! $player instanceof GamePlayer || ! $hex instanceof BoardHexStateData || $hex->building === null) {
            $this->invalidHistory();
        }

        $state = $game->state;

        if ($state->turnStartSnapshot === null) {
            $state->turnStartSnapshot = $state->toArray();
            $state->round->turnStartVersion = $game->version;
        }

        $playerState = $this->playerState($state, $player->id);
        $playerState->resources->tools -= (int) $action->payload['tools'];
        $playerState->resources->coins -= (int) $action->payload['coins'];
        $playerState->resources->coins += (int) ($action->payload['bonus_coins'] ?? 0);
        $playerState->victoryPoints += (int) ($action->payload['victory_points'] ?? 0);
        $hex->building->type = BuildingType::from((string) $action->payload['target']);
        $state->round->hasTakenMainAction = true;
        $nextActiveUserId = $this->createBuildingFollowUpInteraction->execute(
            $state,
            $playerState,
            $hex->id,
            $hex->building->type,
        );
        $game->active_player_id = $nextActiveUserId;
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayFinishTurn(Game $game, Collection $players, GameAction $action): void
    {
        $nextPlayer = $players->firstWhere('id', (int) ($action->payload['next_player_id'] ?? 0));

        if (! $nextPlayer instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $state->turnStartSnapshot = null;
        $state->round->turnStartVersion = null;
        $state->round->hasTakenMainAction = false;
        $state->round->isCurrentTurnIrrevocable = false;
        $game->active_player_id = $nextPlayer->user_id;
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayPass(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $result = $this->applyPassAction->execute(
            $state,
            $this->playerState($state, $player->id),
            RoundBonus::from((string) $action->payload['round_bonus']),
            $players,
        );
        $game->phase = $result['phase'];
        $game->status = $result['phase'] === GamePhase::Finished ? GameStatus::Finished : GameStatus::Active;
        $game->active_player_id = $result['nextActiveUserId'];
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayScienceBonusBooks(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);
        $state = $game->state;

        if (! $player instanceof GamePlayer
            || $state->pendingInteraction?->type !== PendingInteractionType::ChooseScienceBonusBooks
            || $state->pendingInteraction->playerId !== $player->id) {
            $this->invalidHistory();
        }

        $playerState = $this->playerState($state, $player->id);

        foreach ($action->payload['disciplines'] ?? [] as $disciplineValue) {
            $discipline = KnowledgeDiscipline::from((string) $disciplineValue);
            $playerState->resources->books->{$discipline->value}++;
        }

        $state->pendingInteraction = null;
        [$nextPlayer, $nextPhase] = $this->resolveScienceBonusPhase->execute($state, $players);
        $game->phase = $nextPhase;
        $game->active_player_id = $nextPlayer?->user_id;
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replaySacrificePower(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;
        $playerState = $this->playerState($state, $player->id);
        $amount = (int) ($action->payload['amount'] ?? 0);

        if ($amount < 1 || $amount * 2 > $playerState->resources->power->bowlTwo) {
            $this->invalidHistory();
        }

        if ($state->turnStartSnapshot === null) {
            $state->turnStartSnapshot = $state->toArray();
            $state->round->turnStartVersion = $game->version;
        }

        $playerState->resources->power->bowlTwo -= $amount * 2;
        $playerState->resources->power->bowlThree += $amount;
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayResourceExchange(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;

        if ($state->turnStartSnapshot === null) {
            $state->turnStartSnapshot = $state->toArray();
            $state->round->turnStartVersion = $game->version;
        }

        $this->applyResourceExchange->execute(
            $this->playerState($state, $player->id),
            $this->resourceExchanges($action),
        );
        $game->state = $state;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayPowerAction(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;

        if ($state->turnStartSnapshot === null) {
            $state->turnStartSnapshot = $state->toArray();
            $state->round->turnStartVersion = $game->version;
        }

        $this->applyPowerAction->execute(
            $state,
            $this->playerState($state, $player->id),
            PowerAction::from((string) $action->payload['action']),
            (int) ($action->payload['sacrifice_amount'] ?? 0),
        );
        $this->applyReplayedBridge($state, $player->id, $action);
        $game->state = $state;
    }

    private function applyReplayedBridge(GameStateData $state, int $playerId, GameAction $action): void
    {
        $fromHexId = $action->payload['from_hex_id'] ?? null;
        $toHexId = $action->payload['to_hex_id'] ?? null;

        if (! is_string($fromHexId) || ! is_string($toHexId)) {
            return;
        }

        $state->board->bridges[] = new BridgeStateData($fromHexId, $toHexId, $playerId);
        $state->pendingInteraction = null;
    }

    /** @param Collection<int, GamePlayer> $players */
    private function replayBookAction(Game $game, Collection $players, GameAction $action): void
    {
        $player = $players->firstWhere('user_id', $action->player_id);

        if (! $player instanceof GamePlayer) {
            $this->invalidHistory();
        }

        $state = $game->state;

        if ($state->turnStartSnapshot === null) {
            $state->turnStartSnapshot = $state->toArray();
            $state->round->turnStartVersion = $game->version;
        }

        $disciplineValue = $action->payload['discipline'] ?? null;
        $hexId = $action->payload['hex_id'] ?? null;
        $result = $this->applyBookAction->execute(
            $state,
            $this->playerState($state, $player->id),
            BookAction::from((string) $action->payload['action']),
            (array) ($action->payload['book_counts'] ?? []),
            is_string($disciplineValue) ? KnowledgeDiscipline::from($disciplineValue) : null,
            is_string($hexId) ? $hexId : null,
        );
        $game->active_player_id = $result['nextActiveUserId'];
        $game->state = $state;
    }

    /** @return array<string, int|array<string, int>> */
    private function resourceExchanges(GameAction $action): array
    {
        $exchanges = $action->payload['exchanges'] ?? null;

        if (is_array($exchanges)) {
            return $exchanges;
        }

        $bookCounts = array_fill_keys(array_column(KnowledgeDiscipline::cases(), 'value'), 0);
        $normalized = [
            ResourceExchange::PowerToScholar->value => 0,
            ResourceExchange::PowerToTool->value => 0,
            ResourceExchange::PowerToCoin->value => 0,
            ResourceExchange::ScholarToTool->value => 0,
            ResourceExchange::ToolToCoin->value => 0,
            ResourceExchange::PowerToBook->value => $bookCounts,
            ResourceExchange::BookToCoin->value => $bookCounts,
        ];
        $exchange = ResourceExchange::from((string) $action->payload['exchange']);

        if (in_array($exchange, [ResourceExchange::PowerToBook, ResourceExchange::BookToCoin], true)) {
            $discipline = KnowledgeDiscipline::from((string) $action->payload['discipline']);
            $normalized[$exchange->value][$discipline->value] = 1;
        } else {
            $normalized[$exchange->value] = 1;
        }

        return $normalized;
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
        $omarPlayerIds = array_values(array_filter(
            $state->turnOrder,
            static fn (int $playerId): bool => $players->firstWhere('id', $playerId)?->faction === Faction::Omar,
        ));
        $regularPlayerIds = array_values(array_diff($state->turnOrder, $monkPlayerIds));

        return [...$regularPlayerIds, ...array_reverse($regularPlayerIds), ...$omarPlayerIds, ...$monkPlayerIds];
    }

    private function startingBuildingType(GameStateData $state, GamePlayer $player): BuildingType
    {
        $ownedBuildingCount = collect($state->board->hexes)->filter(
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->id,
        )->count();

        return match (true) {
            $player->faction === Faction::Monks => BuildingType::University,
            $player->faction === Faction::Omar && $ownedBuildingCount >= 2 => BuildingType::Tower,
            default => BuildingType::Workshop,
        };
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
