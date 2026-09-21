<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Actions\FindEligibleAnnexHexesAction;
use App\Domain\Game\Actions\FindEligibleBridgePairsAction;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
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
use App\Domain\Game\Enums\RoundBonus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;

final class LegalActionFinder
{
    public function __construct(
        private FindEligibleAnnexHexesAction $findEligibleAnnexHexes,
        private FindEligibleBridgePairsAction $findEligibleBridgePairs,
        private InnovationPurchaseCostCalculator $innovationPurchaseCostCalculator,
        private BookActionOptionFinder $bookActionOptionFinder,
        private PowerActionOptionFinder $powerActionOptionFinder,
        private BuildWorkshopOptionFinder $buildWorkshopOptionFinder,
        private UpgradeBuildingOptionFinder $upgradeBuildingOptionFinder,
        private PaidTerraformingOptionFinder $paidTerraformingOptionFinder,
        private DevelopmentAdvancementOptionFinder $developmentAdvancementOptionFinder,
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
            if ($playerState instanceof GamePlayerStateData
                && $state->pendingInteraction->type === PendingInteractionType::SpendSpades
                && ! isset($state->pendingInteraction->context['selectedHexId'])) {
                $this->appendPaidTerraformingAction($actions, $state, $playerState);
            }
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
        $buildWorkshopOptions = $this->buildWorkshopOptionFinder->execute($state, $player);
        if ($buildWorkshopOptions !== []) {
            $actions[] = new LegalActionData('build_workshop', ['options' => $buildWorkshopOptions]);
        }

        $upgrades = $this->upgradeBuildingOptionFinder->execute($state, $player);
        if ($upgrades !== []) {
            $actions[] = new LegalActionData('upgrade_building', ['options' => $upgrades]);
        }

        foreach ($this->developmentAdvancementOptionFinder->execute($state, $player) as $option) {
            $actions[] = new LegalActionData($option->type(), ['option' => $option]);
        }

        if ($player->resources->scholars > 0) {
            $actions[] = new LegalActionData('send_scholar', ['options' => $this->scholarOptions($game, $player)]);
        }

        $this->appendPaidTerraformingAction($actions, $state, $player);

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

    /** @param list<LegalActionData> $actions */
    private function appendPaidTerraformingAction(
        array &$actions,
        GameStateData $state,
        GamePlayerStateData $player,
    ): void {
        $options = $this->paidTerraformingOptionFinder->execute($state, $player);

        if ($options !== []) {
            $actions[] = new LegalActionData('start_paid_terraforming', ['options' => $options]);
        }
    }

    /** @param list<LegalActionData> $actions */
    private function appendPowerActions(array &$actions, Game $game, GamePlayerStateData $player): void
    {
        $options = $this->powerActionOptionFinder->execute($game->state, $player);

        if ($options !== []) {
            $actions[] = new LegalActionData('use_power_action', ['options' => $options]);
        }
    }

    /** @param list<LegalActionData> $actions */
    private function appendBookActions(array &$actions, Game $game, GamePlayerStateData $player): void
    {
        $options = $this->bookActionOptionFinder->execute($game->state, $player);

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
            $neutralScholarCount = $game->state->neutralKnowledge !== null
                && in_array($discipline->value, $game->state->neutralKnowledge->scholarDisciplineIds, true)
                    ? 1
                    : 0;
            if ($player->scholarPoolSize > 0 && $placed + $neutralScholarCount < 4) {
                $options[] = ['discipline' => $discipline->value, 'place' => true];
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
