<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Game\Actions\ReplayGameHistoryAction;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BridgeStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\PowerAction;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TownTile;
use App\Domain\Game\Services\BuildingAdjacencyChecker;
use App\Domain\Game\Services\CompetencySupply;
use App\Domain\Game\Services\InnovationPurchaseCostCalculator;
use App\Domain\Game\Services\LargestNetworkSizeCalculator;
use App\Domain\Game\Services\PlayerIncomeCalculator;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use BackedEnum;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Game */
class GameResource extends JsonResource
{
    private GameStateData $state;

    public function __construct(mixed $resource)
    {
        parent::__construct($resource);

        /** @var Game $resource */
        $this->state = $resource->state;
    }

    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $playersLoaded = $this->relationLoaded('players');
        $owner = $playersLoaded ? $this->players->firstWhere('seat', 1) : null;
        $isOwner = $owner?->user_id === $request->user()?->id;
        $hasActions = $this->relationLoaded('actions') && $this->actions->isNotEmpty();
        $hasUnsupportedActions = $isOwner && $hasActions && $this->actions()
            ->whereNotIn(
                'type',
                array_map(
                    static fn (\BackedEnum $type): string => (string) $type->value,
                    ReplayGameHistoryAction::SUPPORTED_ACTION_TYPES,
                ),
            )
            ->exists();
        $currentPlayerState = collect($this->state->players)->firstWhere('userId', $request->user()?->id);
        $latestAction = $hasActions ? $this->actions->sortByDesc('sequence')->first() : null;
        $innovationStates = $this->innovationStates($currentPlayerState);

        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'currentRound' => $this->status === GameStatus::Lobby ? null : $this->state->round->number,
            'mapVariant' => $this->state->board->variant->value,
            'maxPlayers' => $this->state->board->variant->maxPlayers(),
            'playersCount' => (int) $this->getAttribute('players_count'),
            'isJoined' => (bool) $this->getAttribute('is_joined'),
            'isOwner' => $isOwner,
            'canUndoLastAction' => app()->environment('local', 'testing')
                && $isOwner
                && $hasActions
                && ! $hasUnsupportedActions,
            'canRestartCurrentTurn' => $this->phase === GamePhase::Actions
                && $this->active_player_id === $request->user()?->id
                && $this->state->turnStartSnapshot !== null
                && $this->state->pendingInteraction?->type !== PendingInteractionType::PowerOffer,
            'canFinishCurrentTurn' => $this->phase === GamePhase::Actions
                && $this->active_player_id === $request->user()?->id
                && ($this->state->pendingInteraction === null
                    || $this->state->pendingInteraction->type === PendingInteractionType::BuildWorkshopAfterTerraforming)
                && $this->state->round->turnStartVersion !== null
                && $this->state->round->hasTakenMainAction,
            'canPass' => $this->phase === GamePhase::Actions
                && $this->active_player_id === $request->user()?->id
                && $this->state->pendingInteraction === null
                && ! $this->state->round->hasTakenMainAction,
            'canSendScholar' => $this->phase === GamePhase::Actions
                && $this->active_player_id === $request->user()?->id
                && $this->state->pendingInteraction === null
                && ! $this->state->round->hasTakenMainAction
                && $currentPlayerState instanceof GamePlayerStateData
                && $currentPlayerState->resources->scholars > 0,
            'canMakeInnovation' => $this->phase === GamePhase::Actions
                && $this->active_player_id === $request->user()?->id
                && $this->state->pendingInteraction === null
                && ! $this->state->round->hasTakenMainAction
                && $currentPlayerState instanceof GamePlayerStateData
                && count($currentPlayerState->inventionIds) < InnovationPurchaseCostCalculator::MAX_INVENTIONS
                && collect($innovationStates)->contains(
                    static fn (array $innovation): bool => $innovation['isAvailable'] && $innovation['isAffordable'],
                ),
            'canPlaceAnnex' => $this->phase === GamePhase::Actions
                && $this->active_player_id === $request->user()?->id
                && $this->state->pendingInteraction === null
                && ! $this->state->round->hasTakenMainAction
                && $currentPlayerState instanceof GamePlayerStateData
                && $currentPlayerState->availableAnnexes > 0
                && collect($this->state->board->hexes)->contains(
                    static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $currentPlayerState->playerId
                        && ! $hex->building->hasAnnex,
                ),
            'activePlayerId' => $this->active_player_id,
            'turnOrder' => $this->state->turnOrder,
            'board' => [
                'variant' => $this->state->board->variant->value,
                'riverBankHexIds' => $this->state->board->riverBankHexIds,
                'edgeHexIds' => $this->state->board->edgeHexIds,
                'bridges' => array_map(
                    static fn (BridgeStateData $bridge): array => [
                        'fromHexId' => $bridge->fromHexId,
                        'toHexId' => $bridge->toHexId,
                        'ownerPlayerId' => $bridge->ownerPlayerId,
                    ],
                    $this->state->board->bridges,
                ),
                'hexes' => array_map(
                    static fn (BoardHexStateData $hex): array => [
                        'id' => $hex->id,
                        'q' => $hex->q,
                        'r' => $hex->r,
                        'initialTerrain' => $hex->initialTerrain->value,
                        'terrain' => $hex->terrain->value,
                        'adjacentHexIds' => $hex->adjacentHexIds,
                        'riverConnectedHexIds' => $hex->riverConnectedHexIds,
                        'building' => $hex->building === null ? null : [
                            'type' => $hex->building->type->value,
                            'ownerPlayerId' => $hex->building->ownerPlayerId,
                            'isNeutral' => $hex->building->isNeutral,
                            'hasAnnex' => $hex->building->hasAnnex,
                        ],
                        'townId' => $hex->townId,
                        'townTileId' => $hex->townTileId,
                    ],
                    $this->state->board->hexes,
                ),
            ],
            'canStart' => $playersLoaded
                && $this->status === GameStatus::Lobby
                && $this->players->count() >= 2
                && $this->players->every(
                    static fn (GamePlayer $player): bool => $player->is_ready,
                ),
            'players' => GamePlayerResource::collection($this->whenLoaded('players')),
            'playerBoardStates' => array_map(
                fn (GamePlayerStateData $player): array => [
                    'playerId' => $player->playerId,
                    'victoryPoints' => $player->victoryPoints,
                    'passOrder' => ($passIndex = array_search($player->playerId, $this->state->passedPlayerIds, true)) === false
                        ? null
                        : $passIndex + 1,
                    'roundBonus' => $player->roundBonus->value,
                    'canUseFactionAction' => $player->faction->hasSpecialAction()
                        && ($player->faction === Faction::Moles
                            ? $player->resources->tools > 0
                                && ! $this->state->round->hasTakenMainAction
                                && count(array_filter(
                                    $this->state->board->bridges,
                                    static fn (BridgeStateData $bridge): bool => $bridge->ownerPlayerId === $player->playerId,
                                )) < 3
                            : ! in_array($player->faction->specialActionId(), $player->usedSpecialActionIds, true)),
                    'canUseCompetencyAction' => in_array(Competency::Competency07->value, $player->competencyIds, true)
                        && ! $this->state->round->hasTakenMainAction
                        && ! in_array(Competency::Competency07->value, $player->usedSpecialActionIds, true),
                    'isCompetencyActionUsed' => in_array(Competency::Competency07->value, $player->usedSpecialActionIds, true),
                    'canUseRoundBonusAction' => $player->roundBonus->hasAvailableSpecialAction()
                        && ! $this->state->round->hasTakenMainAction
                        && ! in_array($player->roundBonus->value, $player->usedSpecialActionIds, true),
                    'isRoundBonusActionUsed' => in_array($player->roundBonus->value, $player->usedSpecialActionIds, true),
                    'scholars' => $player->resources->scholars,
                    'scholarPoolSize' => $player->scholarPoolSize,
                    'scholarDisciplineIds' => $player->scholarDisciplineIds,
                    'coins' => $player->resources->coins,
                    'tools' => $player->resources->tools,
                    'books' => [
                        'banking' => $player->resources->books->banking,
                        'law' => $player->resources->books->law,
                        'engineering' => $player->resources->books->engineering,
                        'medicine' => $player->resources->books->medicine,
                        'unassigned' => $player->resources->books->unassigned,
                    ],
                    'availableBridges' => max(
                        0,
                        3 - count(array_filter(
                            $this->state->board->bridges,
                            static fn (BridgeStateData $bridge): bool => $bridge->ownerPlayerId === $player->playerId,
                        )),
                    ),
                    'competencyIds' => $player->competencyIds,
                    'inventionIds' => $player->inventionIds,
                    'palaceId' => $player->palaceId,
                    'canUsePalaceAction' => $this->canUsePalaceAction($player),
                    'availableInnovationActionIds' => array_values(array_filter(
                        $player->inventionIds,
                        fn (string $innovationId): bool => $this->phase === GamePhase::Actions
                            && $this->state->pendingInteraction === null
                            && ! $this->state->round->hasTakenMainAction
                            && ($innovation = Innovation::tryFrom($innovationId))?->hasSpecialAction() === true
                            && ! in_array($innovation->specialActionId(), $player->usedSpecialActionIds, true),
                    )),
                    'usedInnovationActionIds' => array_values(array_filter(
                        $player->inventionIds,
                        static fn (string $innovationId): bool => ($innovation = Innovation::tryFrom($innovationId))?->hasSpecialAction() === true
                            && in_array($innovation->specialActionId(), $player->usedSpecialActionIds, true),
                    )),
                    'activeTownKeys' => max(
                        0,
                        count($player->townTileIds)
                            - count($player->knowledge->unlockedDisciplines),
                    ),
                    'usedTownKeys' => count($player->knowledge->unlockedDisciplines),
                    'townTileIds' => $player->townTileIds,
                    'activeAnnexes' => count(array_filter(
                        $this->state->board->hexes,
                        static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $player->playerId
                            && $hex->building->hasAnnex,
                    )),
                    'availableAnnexes' => $player->availableAnnexes,
                    'buildingsOnMap' => [
                        'workshop' => $this->buildingCount($player->playerId, BuildingType::Workshop),
                        'guild' => $this->buildingCount($player->playerId, BuildingType::Guild),
                        'school' => $this->buildingCount($player->playerId, BuildingType::School),
                        'university' => $this->buildingCount($player->playerId, BuildingType::University),
                        'palace' => $this->buildingCount($player->playerId, BuildingType::Palace),
                    ],
                    'income' => PlayerIncomeCalculator::calculate($player, $this->state->board),
                    'shippingLevel' => $player->shippingLevel,
                    'largestNetworkSize' => LargestNetworkSizeCalculator::calculate($player, $this->state->board),
                    'canAdvanceShipping' => $player->shippingLevel < 3
                        && $player->resources->coins >= 4
                        && $player->resources->scholars >= 1,
                    'terraformingLevel' => $player->terraformingLevel,
                    'canAdvanceTerraforming' => $player->terraformingLevel < 2
                        && $player->resources->tools >= 1
                        && $player->resources->coins >= ($player->color === PlayerColor::Brown ? 1 : 5)
                        && $player->resources->scholars >= 1,
                    'unassignedSpades' => $player->unassignedSpades,
                    'knowledge' => [
                        'banking' => $player->knowledge->banking,
                        'law' => $player->knowledge->law,
                        'engineering' => $player->knowledge->engineering,
                        'medicine' => $player->knowledge->medicine,
                    ],
                    'power' => [
                        'bowlOne' => $player->resources->power->bowlOne,
                        'bowlTwo' => $player->resources->power->bowlTwo,
                        'bowlThree' => $player->resources->power->bowlThree,
                    ],
                ],
                $this->state->players,
            ),
            'planningBundles' => array_map(
                static fn (PlanningBundleData $bundle): array => [
                    'homeland' => $bundle->homeland->value,
                    'faction' => $bundle->faction->value,
                    'roundBonus' => $bundle->roundBonus->value,
                ],
                $this->state->setupPool?->planningBundles ?? [],
            ),
            'planningSelections' => array_map(
                static fn (PlayerPlanningSelectionData $selection): array => [
                    'playerId' => $selection->playerId,
                    'bundle' => [
                        'homeland' => $selection->bundle->homeland->value,
                        'faction' => $selection->bundle->faction->value,
                        'roundBonus' => $selection->bundle->roundBonus->value,
                    ],
                ],
                $this->state->planningSelections,
            ),
            'planningBundleDescriptions' => [
                'homelands' => $this->enumDescriptions(
                    TerrainType::cases(),
                    static fn (TerrainType $terrain): string => $terrain->description(),
                ),
                'factions' => $this->enumDescriptions(
                    Faction::cases(),
                    static fn (Faction $faction): string => $faction->description(),
                ),
                'roundBonuses' => $this->enumDescriptions(
                    RoundBonus::cases(),
                    static fn (RoundBonus $roundBonus): string => $roundBonus->description(),
                ),
            ],
            'competencyDescriptions' => $this->enumDescriptions(
                Competency::cases(),
                static fn (Competency $competency): string => $competency->description(),
            ),
            'innovationDescriptions' => $this->enumDescriptions(
                Innovation::cases(),
                static fn (Innovation $innovation): string => $innovation->description(),
            ),
            'roundBonusDescriptions' => $this->enumDescriptions(
                RoundBonus::cases(),
                static fn (RoundBonus $roundBonus): string => $roundBonus->description(),
            ),
            'palaceDescriptions' => $this->enumDescriptions(
                PalaceAbility::cases(),
                static fn (PalaceAbility $palace): string => $palace->description(),
            ),
            'knowledgeDisciplineNames' => $this->enumDescriptions(
                KnowledgeDiscipline::cases(),
                static fn (KnowledgeDiscipline $discipline): string => $discipline->displayName(),
            ),
            'roundScoringTiles' => $this->enumValues(
                $this->state->setupPool?->roundScoringTiles ?? [],
            ),
            'finalRoundScoringTile' => $this->enumValue(
                $this->state->setupPool?->additionalFinalRoundGoal,
            ),
            'bookActions' => $this->enumValues(
                $this->state->setupPool?->bookActions ?? [],
            ),
            'usedBookActionIds' => array_values(array_filter(
                $this->enumValues($this->state->setupPool?->bookActions ?? []),
                fn (string $actionId): bool => in_array($actionId, $this->state->round->usedBookActionIds, true),
            )),
            'bookActionStates' => array_map(
                function (BookAction|string $action): array {
                    $bookAction = $action instanceof BookAction ? $action : BookAction::from($action);

                    return [
                        'id' => $bookAction->value,
                        'cost' => $bookAction->cost(),
                        'description' => $bookAction->description(),
                        'isUsed' => in_array(
                            $bookAction->value,
                            $this->state->round->usedBookActionIds,
                            true,
                        ),
                    ];
                },
                $this->state->setupPool?->bookActions ?? [],
            ),
            'powerActions' => array_map(
                fn (PowerAction $action): array => [
                    'id' => $action->value,
                    'cost' => $action->cost($currentPlayerState?->faction),
                    'description' => $action->description($currentPlayerState?->faction),
                    'isUsed' => in_array($action->value, $this->state->round->usedSharedActionIds, true),
                ],
                PowerAction::cases(),
            ),
            'buildingUpgrades' => $this->phase === GamePhase::Actions
                && $this->active_player_id === $request->user()?->id
                && $this->state->pendingInteraction === null
                && ! $this->state->round->hasTakenMainAction
                && $currentPlayerState instanceof GamePlayerStateData
                ? $this->buildingUpgrades($currentPlayerState)
                : [],
            'innovations' => $this->enumValues(
                $this->state->setupPool?->innovations ?? [],
            ),
            'availableInventionIds' => $this->enumValues($this->state->availableInventionIds),
            'innovationStates' => $innovationStates,
            'competencies' => $this->enumValues(
                $this->state->setupPool?->competencies ?? [],
            ),
            'competencyCounts' => array_count_values(CompetencySupply::availableIds($this->state)),
            'availablePalaceIds' => $this->state->availablePalaceIds,
            'availableTownTileIds' => $this->state->availableTownTileIds,
            'townTileDescriptions' => collect(TownTile::cases())->mapWithKeys(
                static fn (TownTile $townTile): array => [$townTile->value => $townTile->description()],
            )->all(),
            'roundBonusOffers' => array_map(
                static fn (RoundBonusOfferData $offer): array => [
                    'roundBonus' => $offer->roundBonus->value,
                    'coins' => $offer->coins,
                ],
                $this->state->setupPool?->availableRoundBonuses ?? [],
            ),
            'pendingInteraction' => $this->state->pendingInteraction === null ? null : [
                'type' => $this->state->pendingInteraction->type->value,
                'playerId' => $this->state->pendingInteraction->playerId,
                'optionIds' => $this->state->pendingInteraction->optionIds,
                'context' => $this->state->pendingInteraction->context,
            ],
            'startingBuildingTurnIndex' => $this->state->startingBuildingTurnIndex,
            'pendingStartingBuildingHexId' => $this->state->pendingStartingBuildingHexId,
            'phase' => $this->phase->value,
            'history' => $this->whenLoaded('actions', fn (): array => [
                'data' => GameHistoryEntryResource::collection(
                    $this->actions->take(GameAction::HISTORY_PAGE_SIZE),
                )->resolve($request),
                'hasMore' => $this->actions->count() > GameAction::HISTORY_PAGE_SIZE,
            ]),
            'createdAt' => $this->created_at?->toISOString(),
        ];
    }

    /**
     * @param list<BackedEnum|string> $values
     * @return list<string>
     */
    private function enumValues(array $values): array
    {
        return array_map(
            static fn (BackedEnum|string $value): string => $value instanceof BackedEnum
                ? (string) $value->value
                : $value,
            $values,
        );
    }

    private function enumValue(BackedEnum|string|null $value): ?string
    {
        return $value instanceof BackedEnum ? (string) $value->value : $value;
    }

    /**
     * @return list<array{
     *     id: string,
     *     isAvailable: bool,
     *     isAffordable: bool,
     *     requiredBooks: array{banking: int, law: int, engineering: int, medicine: int},
     *     extraAnyBooks: int,
     *     totalBooks: int,
     *     coins: int
     * }>
     */
    private function innovationStates(?GamePlayerStateData $player): array
    {
        $innovations = $this->state->setupPool?->innovations ?? [];
        $playerCount = $this->state->setupPool?->playerCount ?? count($this->state->players);
        $calculator = new InnovationPurchaseCostCalculator();
        $ownedCount = $player instanceof GamePlayerStateData ? count($player->inventionIds) : 0;
        $skipsSecondInventionSurcharge = $player instanceof GamePlayerStateData
            && $player->homeland === TerrainType::Wasteland;
        $hasPalace = $player instanceof GamePlayerStateData
            && $this->buildingCount($player->playerId, BuildingType::Palace) > 0;

        return array_map(
            function (int $index, Innovation|string $innovation) use (
                $calculator,
                $playerCount,
                $ownedCount,
                $skipsSecondInventionSurcharge,
                $hasPalace,
                $player,
            ): array {
                $innovationId = $innovation instanceof Innovation ? $innovation->value : $innovation;
                $cost = $calculator->cost(
                    $playerCount,
                    $index,
                    min($ownedCount, InnovationPurchaseCostCalculator::MAX_INVENTIONS - 1),
                    $skipsSecondInventionSurcharge,
                    $hasPalace,
                );

                return [
                    'id' => $innovationId,
                    'isAvailable' => in_array($innovationId, $this->state->availableInventionIds, true),
                    'isAffordable' => $player instanceof GamePlayerStateData
                        && $player->resources->coins >= $cost['coins']
                        && $player->resources->books->banking >= $cost['requiredBooks']['banking']
                        && $player->resources->books->law >= $cost['requiredBooks']['law']
                        && $player->resources->books->engineering >= $cost['requiredBooks']['engineering']
                        && $player->resources->books->medicine >= $cost['requiredBooks']['medicine']
                        && $player->resources->books->banking
                            + $player->resources->books->law
                            + $player->resources->books->engineering
                            + $player->resources->books->medicine >= $cost['totalBooks'],
                    ...$cost,
                ];
            },
            array_keys($innovations),
            $innovations,
        );
    }

    private function buildingCount(int $playerId, BuildingType $type): int
    {
        return count(array_filter(
            $this->state->board->hexes,
            static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerId
                && $hex->building->type === $type
                && ! $hex->building->isNeutral,
        ));
    }

    private function canUsePalaceAction(GamePlayerStateData $player): bool
    {
        $palace = PalaceAbility::tryFrom((string) $player->palaceId);

        return $palace?->hasSpecialAction() === true
            && ! in_array($palace->specialActionId(), $player->usedSpecialActionIds, true);
    }

    /** @return list<array{hexId: string, source: string, target: string, tools: int, coins: int}> */
    private function buildingUpgrades(GamePlayerStateData $player): array
    {
        $upgrades = [];

        foreach ($this->state->board->hexes as $hex) {
            if ($hex->building === null
                || $hex->building->ownerPlayerId !== $player->playerId
                || $hex->building->isNeutral) {
                continue;
            }

            $hasAdjacentOpponent = BuildingAdjacencyChecker::hasOpponent(
                $this->state->board,
                $hex,
                $player->playerId,
            );

            foreach ($hex->building->type->upgradeOptions() as $target) {
                $cost = $hex->building->type->upgradeCostTo($target, $hasAdjacentOpponent);

                if ($player->resources->tools < $cost['tools']
                    || $player->resources->coins < $cost['coins']
                    || $this->buildingCount($player->playerId, $target) >= $target->supplyLimit()) {
                    continue;
                }

                $upgrades[] = [
                    'hexId' => $hex->id,
                    'source' => $hex->building->type->value,
                    'target' => $target->value,
                    'tools' => $cost['tools'],
                    'coins' => $cost['coins'],
                ];
            }
        }

        return $upgrades;
    }

    /**
     * @template T of BackedEnum
     * @param list<T> $values
     * @param callable(T): string $description
     * @return array<string, string>
     */
    private function enumDescriptions(array $values, callable $description): array
    {
        $descriptions = [];

        foreach ($values as $value) {
            $descriptions[(string) $value->value] = $description($value);
        }

        return $descriptions;
    }
}
