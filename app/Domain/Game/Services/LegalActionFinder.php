<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\FindEligibleAnnexHexesAction;
use App\Domain\Game\Actions\FindEligibleBridgePairsAction;
use App\Domain\Game\Actions\FindEligibleMoleTunnelHexesAction;
use App\Domain\Game\Actions\FindEligiblePalaceFlightHexesAction;
use App\Domain\Game\Actions\FindEligibleTerraformHexesAction;
use App\Domain\Game\Actions\FindReachableLandHexesAction;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\LegalActionData;
use App\Domain\Game\Data\PendingInteractionData;
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
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;

final class LegalActionFinder
{
    public function __construct(
        private FindReachableLandHexesAction $findReachableLandHexes,
        private FindEligibleAnnexHexesAction $findEligibleAnnexHexes,
        private FindEligibleBridgePairsAction $findEligibleBridgePairs,
        private FindEligibleTerraformHexesAction $findEligibleTerraformHexes,
        private FindEligibleMoleTunnelHexesAction $findEligibleMoleTunnelHexes,
        private FindEligiblePalaceFlightHexesAction $findEligiblePalaceFlightHexes,
        private InnovationPurchaseCostCalculator $innovationPurchaseCostCalculator,
    ) {
    }

    /** @return list<LegalActionData> */
    public function execute(Game $game, User $user): array
    {
        $player = $game->players()->whereBelongsTo($user)->first();

        if (! $player instanceof GamePlayer || $game->status === GameStatus::Finished) {
            return [];
        }

        $state = $game->state;
        $playerState = collect($state->players)->firstWhere('playerId', $player->id);

        if ($state->pendingInteraction !== null) {
            $actions = $this->pendingActions($game, $player, $state->pendingInteraction);
            if ($game->phase === GamePhase::Actions && $playerState instanceof GamePlayerStateData
                && $game->active_player_id === $user->id) {
                $this->appendResourceActions($actions, $playerState);
            }

            return $actions;
        }

        if ($game->active_player_id !== $user->id) {
            return [];
        }

        if ($game->phase === GamePhase::Setup) {
            return $this->setupActions($game, $player);
        }

        if ($game->phase !== GamePhase::Actions || ! $playerState instanceof GamePlayerStateData) {
            return [];
        }

        return $this->actionPhaseActions($game, $playerState);
    }

    /** @return list<LegalActionData> */
    private function pendingActions(Game $game, GamePlayer $player, PendingInteractionData $interaction): array
    {
        if ($interaction->playerId !== $player->id || $game->active_player_id !== $player->user_id) {
            return [];
        }

        $selectedHexId = $interaction->context['selectedHexId'] ?? null;
        $bridgeIsStaged = isset($interaction->context['selectedFromHexId'], $interaction->context['selectedToHexId']);
        $parameters = ['options' => $interaction->optionIds, 'context' => $interaction->context];

        return match ($interaction->type) {
            PendingInteractionType::ChooseStartingResources => [new LegalActionData('choose_starting_resources', $parameters)],
            PendingInteractionType::PowerOffer => [new LegalActionData('resolve_power_offer', [
                'accept' => [true, false],
                'powerAmount' => (int) ($interaction->context['powerAmount'] ?? 0),
            ])],
            PendingInteractionType::ChooseTown => [new LegalActionData('choose_town', $parameters)],
            PendingInteractionType::ChooseTownBooks,
            PendingInteractionType::ChooseFelineTownBonus,
            PendingInteractionType::ChooseScienceBonusBooks,
            PendingInteractionType::ChooseInnovationBooks,
            PendingInteractionType::ChooseShippingBooks,
            PendingInteractionType::ChooseTerraformingBooks,
            PendingInteractionType::ChoosePalaceBooks => [new LegalActionData('distribute_rewards', $parameters)],
            PendingInteractionType::OfferPalaceWaterTown => [new LegalActionData('resolve_palace_water_town', [
                ...$parameters,
                'accept' => [true, false],
            ])],
            PendingInteractionType::ChoosePalace => [new LegalActionData('choose_palace', $parameters)],
            PendingInteractionType::ChooseCompetency => [new LegalActionData('choose_competency', $parameters)],
            PendingInteractionType::ChooseRoundBonus => [new LegalActionData('choose_round_bonus', $parameters)],
            PendingInteractionType::BuildWorkshopAfterTerraforming => [new LegalActionData('resolve_workshop_after_terraforming', [
                ...$parameters,
                'build' => [true, false],
            ])],
            PendingInteractionType::PlaceNeutralBuilding => [new LegalActionData('place_neutral_building', $parameters)],
            PendingInteractionType::SpendSpades => is_string($selectedHexId)
                ? [new LegalActionData('confirm_spades'), new LegalActionData('undo_spades')]
                : [new LegalActionData('stage_spades', $parameters)],
            PendingInteractionType::PlaceBridge => $bridgeIsStaged
                ? [new LegalActionData('confirm_bridge'), new LegalActionData('undo_bridge')]
                : [new LegalActionData('stage_bridge', [
                    'options' => $this->findEligibleBridgePairs->execute(
                        $game->state,
                        $player->id,
                        canBuildAcrossTerrain: ($interaction->context['source'] ?? null) === 'faction',
                    ),
                    'context' => $interaction->context,
                ])],
            PendingInteractionType::PlacePalaceGuild => is_string($selectedHexId)
                ? [new LegalActionData('confirm_palace_guild'), new LegalActionData('undo_palace_guild')]
                : [new LegalActionData('stage_palace_guild', $parameters)],
        };
    }

    /** @return list<LegalActionData> */
    private function setupActions(Game $game, GamePlayer $player): array
    {
        $state = $game->state;

        if (count($state->planningSelections) < count($state->turnOrder)) {
            if ($state->setupPool === null) {
                return [];
            }

            return [new LegalActionData('choose_planning_bundle', [
                'homelands' => array_map(
                    static fn ($bundle): string => $bundle->homeland->value,
                    $state->setupPool->planningBundles,
                ),
            ])];
        }

        if ($state->pendingStartingBuildingHexId !== null) {
            return [new LegalActionData('confirm_starting_building'), new LegalActionData('undo_starting_building')];
        }

        $hexIds = array_values(array_map(
            static fn (BoardHexStateData $hex): string => $hex->id,
            array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $hex): bool => $hex->building === null && $hex->terrain === $player->homeland,
            ),
        ));

        return $hexIds === [] ? [] : [new LegalActionData('stage_starting_building', ['hexIds' => $hexIds])];
    }

    /** @return list<LegalActionData> */
    private function actionPhaseActions(Game $game, GamePlayerStateData $player): array
    {
        $state = $game->state;

        if ($state->round->hasTakenMainAction) {
            return $state->round->turnStartVersion === null
                ? []
                : [new LegalActionData('confirm_turn'), new LegalActionData('restart_turn')];
        }

        $actions = [new LegalActionData('pass', $this->passParameters($game, $player))];
        $this->appendResourceActions($actions, $player);
        $reachableHexIds = $this->findReachableLandHexes->execute($state, $player);
        $workshopCount = $this->buildingCount($state->board->hexes, $player->playerId, BuildingType::Workshop);
        $buildableHexIds = array_values(array_map(
            static fn (BoardHexStateData $hex): string => $hex->id,
            array_filter(
                $state->board->hexes,
                static fn (BoardHexStateData $hex): bool => in_array($hex->id, $reachableHexIds, true)
                    && $hex->building === null && $hex->terrain === $player->homeland,
            ),
        ));

        if ($player->resources->tools >= 1 && $player->resources->coins >= 2
            && $workshopCount < BuildingType::Workshop->supplyLimit() && $buildableHexIds !== []) {
            $actions[] = new LegalActionData('build_workshop', ['hexIds' => $buildableHexIds]);
        }

        $upgrades = $this->buildingUpgrades($game, $player);
        if ($upgrades !== []) {
            $actions[] = new LegalActionData('upgrade_building', ['options' => $upgrades]);
        }

        if ($player->shippingLevel < 3 && $player->resources->coins >= 4 && $player->resources->scholars >= 1) {
            $actions[] = new LegalActionData('advance_shipping');
        }

        $terraformingCoinCost = $player->color === PlayerColor::Brown ? 1 : 5;
        if ($player->terraformingLevel < 2 && $player->resources->tools >= 1
            && $player->resources->coins >= $terraformingCoinCost && $player->resources->scholars >= 1) {
            $actions[] = new LegalActionData('advance_terraforming');
        }

        if ($player->resources->scholars > 0) {
            $actions[] = new LegalActionData('send_scholar', ['options' => $this->scholarOptions($game, $player)]);
        }

        $terraformOptions = $this->paidTerraformOptions($game, $player);
        if ($terraformOptions !== []) {
            $actions[] = new LegalActionData('start_paid_terraforming', ['options' => $terraformOptions]);
        }

        $this->appendPowerActions($actions, $game, $player);
        $this->appendBookActions($actions, $game, $player);
        $this->appendSpecialActions($actions, $player);
        $this->appendInnovationPurchases($actions, $game, $player);

        if ($player->availableAnnexes > 0) {
            $hexIds = $this->findEligibleAnnexHexes->execute($state, $player->playerId);
            if ($hexIds !== []) {
                $actions[] = new LegalActionData('place_annex', ['hexIds' => $hexIds]);
            }
        }

        return $actions;
    }

    /** @param list<LegalActionData> $actions */
    private function appendResourceActions(array &$actions, GamePlayerStateData $player): void
    {
        $exchangeLimits = [
            'power' => $player->resources->power->bowlThree,
            'scholars' => $player->resources->scholars,
            'tools' => $player->resources->tools,
            'books' => [
                'banking' => $player->resources->books->banking,
                'law' => $player->resources->books->law,
                'engineering' => $player->resources->books->engineering,
                'medicine' => $player->resources->books->medicine,
            ],
        ];
        if ($exchangeLimits['power'] > 0 || $exchangeLimits['scholars'] > 0 || $exchangeLimits['tools'] > 0
            || array_sum($exchangeLimits['books']) > 0) {
            $actions[] = new LegalActionData('exchange_resources', ['available' => $exchangeLimits]);
        }

        $maximumSacrifice = intdiv($player->resources->power->bowlTwo, 2);
        if ($maximumSacrifice > 0) {
            $actions[] = new LegalActionData('sacrifice_power', ['amount' => ['min' => 1, 'max' => $maximumSacrifice]]);
        }
    }

    /** @return list<array{hexId: string, useTunnel: bool, useFlight: bool, toolCost: int, scholarCost: int}> */
    private function paidTerraformOptions(Game $game, GamePlayerStateData $player): array
    {
        $state = $game->state;
        $toolCostPerSpade = max(1, 3 - $player->terraformingLevel);
        $modes = [
            [false, false, $this->findEligibleTerraformHexes->execute($state, $player, $player->homeland)],
            [true, false, $this->findEligibleMoleTunnelHexes->execute($state, $player)],
            [false, true, $this->findEligiblePalaceFlightHexes->execute($state, $player)],
        ];
        $options = [];

        foreach ($modes as [$useTunnel, $useFlight, $hexIds]) {
            foreach ($hexIds as $hexId) {
                $hex = collect($state->board->hexes)->firstWhere('id', $hexId);
                $spadeCount = $hex?->terrain->spadesTo($player->homeland) ?? 0;
                $toolCost = $spadeCount * $toolCostPerSpade + ($useTunnel ? 1 : 0);
                $scholarCost = $useFlight ? 1 : 0;
                if ($spadeCount > 0 && $toolCost <= $player->resources->tools
                    && $scholarCost <= $player->resources->scholars) {
                    $options[] = compact('hexId', 'useTunnel', 'useFlight', 'toolCost', 'scholarCost');
                }
            }
        }

        return $options;
    }

    /** @param list<LegalActionData> $actions */
    private function appendPowerActions(array &$actions, Game $game, GamePlayerStateData $player): void
    {
        $options = [];
        foreach (PowerAction::cases() as $powerAction) {
            if (in_array($powerAction->value, $game->state->round->usedSharedActionIds, true)
                || ($powerAction === PowerAction::GainScholar && $player->resources->scholars >= $player->scholarPoolSize)) {
                continue;
            }

            $sacrificeAmount = max(0, $powerAction->cost($player->faction) - $player->resources->power->bowlThree);
            if ($sacrificeAmount * 2 <= $player->resources->power->bowlTwo) {
                $options[] = ['action' => $powerAction->value, 'sacrificeAmount' => $sacrificeAmount];
            }
        }

        if ($options !== []) {
            $actions[] = new LegalActionData('use_power_action', ['options' => $options]);
        }
    }

    /** @param list<LegalActionData> $actions */
    private function appendBookActions(array &$actions, Game $game, GamePlayerStateData $player): void
    {
        if ($game->state->setupPool === null) {
            return;
        }

        $options = [];
        foreach ($game->state->setupPool->bookActions as $action) {
            if (! in_array($action->value, $game->state->round->usedBookActionIds, true)
                && $this->bookTotal($player) >= $action->cost()) {
                $options[] = ['action' => $action->value, 'bookCost' => $action->cost()];
            }
        }

        if ($options !== []) {
            $actions[] = new LegalActionData('use_book_action', ['options' => $options]);
        }
    }

    /** @param list<LegalActionData> $actions */
    private function appendSpecialActions(array &$actions, GamePlayerStateData $player): void
    {
        $disciplineIds = array_column(KnowledgeDiscipline::cases(), 'value');
        $factionActionId = $player->faction->specialActionId();
        if ($player->faction->hasSpecialAction()
            && ($player->faction === Faction::Moles ? $player->resources->tools > 0 : ! in_array($factionActionId, $player->usedSpecialActionIds, true))) {
            $actions[] = new LegalActionData('use_faction_action', $player->faction === Faction::Philosophers ? ['disciplines' => $disciplineIds] : []);
        }

        if (in_array(Competency::Competency07->value, $player->competencyIds, true)
            && ! in_array(Competency::Competency07->value, $player->usedSpecialActionIds, true)) {
            $actions[] = new LegalActionData('use_competency_action');
        }

        if ($player->roundBonus->hasAvailableSpecialAction()
            && ! in_array($player->roundBonus->value, $player->usedSpecialActionIds, true)) {
            $actions[] = new LegalActionData('use_round_bonus_action', $player->roundBonus === RoundBonus::Knowledge ? ['disciplines' => $disciplineIds] : []);
        }

        $palace = PalaceAbility::tryFrom((string) $player->palaceId);
        if ($palace?->hasSpecialAction() === true && ! in_array($palace->specialActionId(), $player->usedSpecialActionIds, true)) {
            $actions[] = new LegalActionData('use_palace_action', ['palace' => $palace->value]);
        }

        $innovations = array_values(array_filter(
            $player->inventionIds,
            static fn (string $id): bool => ($innovation = Innovation::tryFrom($id))?->hasSpecialAction() === true
                && ! in_array($innovation->specialActionId(), $player->usedSpecialActionIds, true),
        ));
        if ($innovations !== []) {
            $actions[] = new LegalActionData('use_innovation_action', ['innovations' => $innovations]);
        }
    }

    /** @param list<LegalActionData> $actions */
    private function appendInnovationPurchases(array &$actions, Game $game, GamePlayerStateData $player): void
    {
        if ($game->state->setupPool === null
            || count($player->inventionIds) >= InnovationPurchaseCostCalculator::MAX_INVENTIONS) {
            return;
        }

        $innovations = $game->state->setupPool->innovations;
        $playerCount = $game->state->setupPool->playerCount;
        $options = [];
        foreach ($innovations as $index => $innovation) {
            if (! in_array($innovation->value, $game->state->availableInventionIds, true)) {
                continue;
            }
            $cost = $this->innovationPurchaseCostCalculator->cost(
                $playerCount,
                $index,
                count($player->inventionIds),
                $player->homeland->value === 'wasteland',
                $this->buildingCount($game->state->board->hexes, $player->playerId, BuildingType::Palace) > 0,
            );
            if ($player->resources->coins >= $cost['coins'] && $this->canPayBooks($player, $cost)) {
                $options[] = ['innovation' => $innovation->value, ...$cost];
            }
        }

        if ($options !== []) {
            $actions[] = new LegalActionData('make_innovation', ['options' => $options]);
        }
    }

    /** @return list<array{discipline: string, place: bool}> */
    private function scholarOptions(Game $game, GamePlayerStateData $player): array
    {
        $options = [];
        foreach (KnowledgeDiscipline::cases() as $discipline) {
            $options[] = ['discipline' => $discipline->value, 'place' => false];
            $placed = collect($game->state->players)->sum(
                static fn (GamePlayerStateData $candidate): int => count(array_filter(
                    $candidate->scholarDisciplineIds,
                    static fn (string $id): bool => $id === $discipline->value,
                )),
            );
            if ($player->scholarPoolSize > 0 && $placed < 4) {
                $options[] = ['discipline' => $discipline->value, 'place' => true];
            }
        }

        return $options;
    }

    /** @return list<array{hexId: string, source: string, target: string, tools: int, coins: int}> */
    private function buildingUpgrades(Game $game, GamePlayerStateData $player): array
    {
        $options = [];
        foreach ($game->state->board->hexes as $hex) {
            if ($hex->building === null || $hex->building->ownerPlayerId !== $player->playerId || $hex->building->isNeutral) {
                continue;
            }
            $hasOpponent = BuildingAdjacencyChecker::hasOpponent($game->state->board, $hex, $player->playerId);
            foreach ($hex->building->type->upgradeOptions() as $target) {
                $cost = $hex->building->type->upgradeCostTo($target, $hasOpponent);
                if ($player->resources->tools >= $cost['tools'] && $player->resources->coins >= $cost['coins']
                    && $this->buildingCount($game->state->board->hexes, $player->playerId, $target) < $target->supplyLimit()) {
                    $options[] = ['hexId' => $hex->id, 'source' => $hex->building->type->value, 'target' => $target->value, ...$cost];
                }
            }
        }

        return $options;
    }

    /** @return array<string, mixed> */
    private function passParameters(Game $game, GamePlayerStateData $player): array
    {
        $schoolCount = $player->roundBonus === RoundBonus::PassSchool
            ? $this->buildingCount($game->state->board->hexes, $player->playerId, BuildingType::School)
            : 0;

        return $schoolCount > 0 ? ['knowledgeSteps' => $schoolCount, 'disciplines' => array_column(KnowledgeDiscipline::cases(), 'value')] : [];
    }

    /** @param list<BoardHexStateData> $hexes */
    private function buildingCount(array $hexes, int $playerId, BuildingType $type): int
    {
        return count(array_filter($hexes, static fn (BoardHexStateData $hex): bool => $hex->building?->ownerPlayerId === $playerId
            && $hex->building->type === $type && ! $hex->building->isNeutral));
    }

    private function bookTotal(GamePlayerStateData $player): int
    {
        return $player->resources->books->banking + $player->resources->books->law
            + $player->resources->books->engineering + $player->resources->books->medicine;
    }

    /** @param array{requiredBooks: array{banking: int, law: int, engineering: int, medicine: int}, totalBooks: int} $cost */
    private function canPayBooks(GamePlayerStateData $player, array $cost): bool
    {
        foreach ($cost['requiredBooks'] as $discipline => $count) {
            if ($player->resources->books->{$discipline} < $count) {
                return false;
            }
        }

        return $this->bookTotal($player) >= $cost['totalBooks'];
    }
}
