<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Data\StrategicPlanData;
use App\Domain\Automation\Enums\StrategicApproach;
use App\Domain\Automation\Enums\StrategicObjective;
use App\Domain\Automation\Evaluation\Services\InnovationEvaluator;
use App\Domain\Automation\Evaluation\Services\OwnedBuildingComponentFinder;
use App\Domain\Automation\Evaluation\Services\PalaceAbilityEvaluator;
use App\Domain\Automation\Evaluation\Services\StrategicPriorities;
use App\Domain\Automation\Evaluation\Services\TownAnnexPlanningEvaluator;
use App\Domain\GameEngine\Board\Actions\FindEligibleBridgePairsAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Board\Services\BridgeSupply;
use App\Domain\GameEngine\Board\Services\LargestNetworkSizeCalculator;
use App\Domain\GameEngine\Economy\Data\BookPaymentData;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Services\InnovationPurchaseCostCalculator;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringGoal;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;

final class StrategicPlanner
{
    private const int PLAN_SWITCH_MARGIN = 80;

    private const int MAX_ACTION_ADJUSTMENT = 40;

    private const int ACTION_EFFORT_WEIGHT = 16;

    private const int TOOL_EFFORT_WEIGHT = 4;

    private const int COIN_EFFORT_WEIGHT = 2;

    private const int ANNEX_EFFORT_WEIGHT = 20;

    private const int BOOK_EFFORT_WEIGHT = 12;

    private const int REQUIRED_BOOK_PROGRESS_WEIGHT = 25;

    private const int REQUIRED_TOOL_PROGRESS_WEIGHT = 20;

    private const int REQUIRED_COIN_PROGRESS_WEIGHT = 10;

    private const int STRATEGIC_COMPLETION_VALUE = 160;

    private const int SCHOLAR_EFFORT_WEIGHT = 20;

    public function __construct(
        private OwnedBuildingComponentFinder $componentFinder,
        private TownAnnexPlanningEvaluator $townPlanner,
        private InnovationPurchaseCostCalculator $innovationCostCalculator,
        private InnovationEvaluator $innovationEvaluator,
        private PalaceAbilityEvaluator $palaceAbilityEvaluator,
        private FindEligibleBridgePairsAction $findEligibleBridgePairs,
        private BridgeSupply $bridgeSupply,
        private NetworkConnectionPlanner $networkConnectionPlanner,
        private StrategicPlanFundingValidator $fundingValidator,
    ) {
    }

    public function execute(GameStateData $state, int $playerId, ?StrategicPlanData $previous = null): ?StrategicPlanData
    {
        $player = collect($state->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData || $state->round->phase === GamePhase::Setup
            || $state->round->phase === GamePhase::Finished) {
            return null;
        }
        $previous ??= $player->strategicPlan;
        $hexes = collect($state->board->hexes)->keyBy('id');
        if ($previous !== null && array_any($previous->expansionHexIds, static fn (string $id): bool =>
            $hexes->get($id)?->building !== null && $hexes->get($id)->building->ownerPlayerId !== $playerId)) {
            $previous = null;
        }
        if ($previous !== null && $previous->plannedRound === $state->round->number) {
            $retained = $this->revalidateCurrentPlan($state, $player, $previous);
            if ($retained !== null && $this->fundingValidator->execute($state, $player, $retained)) {
                return $retained;
            }
        }
        $townIds = [];
        foreach ($state->board->hexes as $hex) {
            if ($hex->building?->ownerPlayerId === $playerId && $hex->townId !== null) {
                $townIds[$hex->townId] = true;
            }
        }
        $components = $this->componentFinder->execute($state, $playerId);
        $candidates = [];
        foreach ($components as $component) {
            if (array_any($component, static fn (BoardHexStateData $hex): bool => $hex->townId !== null)) {
                continue;
            }
            $planning = $this->townPlanner->execute($state, $player, [$component], [0], compareDevelopmentEconomy: true);
            if ($planning->projectedTowns <= count($townIds)) {
                continue;
            }
            $discourageAnnex = $state->round->number < 5
                && RoundScoringTile::tryFrom((string) $state->round->scoringTileId)?->goal() !== RoundScoringGoal::Town;
            if ($discourageAnnex && $planning->requiredAnnexes > 0) {
                $withoutAnnexes = clone $player;
                $withoutAnnexes->availableAnnexes = 0;
                $withoutAnnexPlan = $this->townPlanner->execute($state, $withoutAnnexes, [$component], [0], compareDevelopmentEconomy: true);
                if ($withoutAnnexPlan->projectedTowns > count($townIds)) {
                    $planning = $withoutAnnexPlan;
                } elseif ($this->hasVacantExpansionSpace($state, $component)) {
                    continue;
                }
            }
            $anchors = array_values(array_map(static fn (BoardHexStateData $hex): string => $hex->id, array_filter(
                $component,
                static fn (BoardHexStateData $hex): bool => ! $hex->building->isNeutral
            )));
            if ($anchors === []) {
                continue;
            }
            sort($anchors, SORT_STRING);
            $candidates[] = new StrategicPlanData(
                StrategicObjective::FoundTown,
                $anchors,
                $planning->expansionHexIds,
                $planning->reservedTools,
                $planning->reservedCoins,
                $planning->requiredAnnexes,
                $planning->score,
                $planning->remainingActions,
                approach: StrategicApproach::TownDevelopment,
            );
        }
        $innovation = $this->innovationPlan($state, $player);
        if ($innovation !== null) {
            $candidates[] = $innovation;
        }
        $palace = $this->palacePlan($state, $player);
        if ($palace !== null) {
            $candidates[] = $palace;
        }
        $network = $this->networkPlan($state, $player, $components);
        if ($network !== null) {
            $candidates[] = $network;
        }
        $best = null;
        $retained = null;
        foreach ($candidates as $candidate) {
            if (! $this->fundingValidator->execute($state, $player, $candidate)) {
                continue;
            }
            $candidate->plannedRound = $state->round->number;
            if (in_array($candidate->objective, [StrategicObjective::FoundTown, StrategicObjective::ConnectNetwork], true)) {
                $candidate->score = StrategicPriorities::settlementValue($state, $candidate->score);
            }
            if ($best === null || $candidate->score > $best->score) {
                $best = $candidate;
            }
            if ($previous !== null && $this->sameIntention($previous, $candidate)) {
                $retained = $candidate;
            }
        }

        return $retained !== null && ($best === null || $best->score <= $retained->score + self::PLAN_SWITCH_MARGIN)
            ? $retained : $best;
    }

    public function actionAdjustment(GameStateData $before, GameStateData $after, int $playerId, StrategicPlanData $plan): int
    {
        if (! in_array($before->round->phase, [GamePhase::Actions, GamePhase::Income, GamePhase::ScienceBonus], true)) {
            return 0;
        }
        $previous = collect($before->board->hexes)->keyBy('id');
        $player = collect($before->players)->firstWhere('playerId', $playerId);
        $nextPlayer = collect($after->players)->firstWhere('playerId', $playerId);
        if (! $player instanceof GamePlayerStateData || ! $nextPlayer instanceof GamePlayerStateData) {
            return 0;
        }
        $completed = $plan->objective === StrategicObjective::MakeInnovation
            ? in_array($plan->targetId, $nextPlayer->inventionIds, true)
            : ($plan->objective === StrategicObjective::BuildPalace && $nextPlayer->palaceId === $plan->targetId);
        $hasTownBoardProgress = false;
        if ($plan->approach === StrategicApproach::Shipping) {
            $completed = $nextPlayer->shippingLevel >= $plan->targetLevel;
        }
        foreach ($after->board->hexes as $hex) {
            $original = $previous->get($hex->id);
            if (! $original instanceof BoardHexStateData) {
                continue;
            }
            if ($plan->objective === StrategicObjective::FoundTown && in_array($hex->id, $plan->anchorHexIds, true)
                && $original->townId === null && $hex->townId !== null) {
                $completed = true;
            }
            if ($plan->objective === StrategicObjective::FoundTown
                && ((in_array($hex->id, $plan->anchorHexIds, true)
                    && ($hex->building?->type !== $original->building?->type
                        || $hex->building?->hasAnnex !== $original->building?->hasAnnex))
                    || (in_array($hex->id, $plan->expansionHexIds, true)
                        && ($hex->terrain !== $original->terrain || $hex->building !== null)))) {
                $hasTownBoardProgress = true;
            }
            if ($plan->objective === StrategicObjective::BuildPalace && in_array($hex->id, $plan->anchorHexIds, true)
                && $hex->building?->ownerPlayerId === $playerId && $hex->building->type === BuildingType::Palace) {
                $completed = true;
            }
            if ($plan->approach === StrategicApproach::Build && $hex->id === $plan->targetId
                && $hex->building?->ownerPlayerId === $playerId
                && array_all($plan->expansionHexIds, static fn (string $id): bool =>
                    collect($after->board->hexes)->firstWhere('id', $id)?->building?->ownerPlayerId === $playerId)) {
                $completed = true;
            }
        }
        if ($plan->approach === StrategicApproach::Bridge && $plan->targetId !== null && $plan->secondaryTargetId !== null) {
            $from = $plan->targetId;
            $to = $plan->secondaryTargetId;
            $completed = collect($after->board->bridges)->contains(static fn ($bridge): bool =>
                $bridge->ownerPlayerId === $playerId
                && (($bridge->fromHexId === $from && $bridge->toHexId === $to)
                    || ($bridge->fromHexId === $to && $bridge->toHexId === $from)));
        }
        if ($completed) {
            return self::MAX_ACTION_ADJUSTMENT;
        }
        $progress = 0;
        $remainingPlan = $plan;
        if ($plan->objective === StrategicObjective::MakeInnovation && $plan->requiredBooks !== null) {
            $progress = max(-self::MAX_ACTION_ADJUSTMENT, min(
                self::MAX_ACTION_ADJUSTMENT,
                ($this->fulfilledInnovationBooks($nextPlayer, $plan) - $this->fulfilledInnovationBooks($player, $plan))
                    * self::REQUIRED_BOOK_PROGRESS_WEIGHT
            ));
        }
        if ($hasTownBoardProgress) {
            $nextPlan = $this->updatedTownPlan($after, $nextPlayer, $plan);
            if ($nextPlan !== null) {
                $remainingPlan = $nextPlan;
                $progress = min(self::MAX_ACTION_ADJUSTMENT, max(0, $this->remainingEffort($plan) - $this->remainingEffort($nextPlan)));
            }
        }
        if ($plan->objective === StrategicObjective::ConnectNetwork && $plan->approach === StrategicApproach::Build) {
            $nextPlan = $this->updatedNetworkRoute($after, $nextPlayer, $plan);
            if ($nextPlan !== null) {
                $remainingPlan = $nextPlan;
                $progress = min(self::MAX_ACTION_ADJUSTMENT, max(0, $this->remainingEffort($plan) - $this->remainingEffort($nextPlan)));
            }
        }
        if ($plan->approach === StrategicApproach::Shipping && $nextPlayer->shippingLevel > $player->shippingLevel) {
            $steps = max(0, (int) $plan->targetLevel - $nextPlayer->shippingLevel);
            $remainingPlan = clone $plan;
            $remainingPlan->reservedCoins = $steps * 4;
            $remainingPlan->reservedScholars = $steps;
            $progress = min(
                self::MAX_ACTION_ADJUSTMENT,
                ($nextPlayer->shippingLevel - $player->shippingLevel) * self::ACTION_EFFORT_WEIGHT
            );
        }
        $toolShortage = max(0, $remainingPlan->reservedTools - $nextPlayer->resources->tools) - max(0, $plan->reservedTools - $player->resources->tools);
        $coinShortage = max(0, $remainingPlan->reservedCoins - $nextPlayer->resources->coins) - max(0, $plan->reservedCoins - $player->resources->coins);
        $annexShortage = max(0, $remainingPlan->requiredAnnexes - $nextPlayer->availableAnnexes) - max(0, $plan->requiredAnnexes - $player->availableAnnexes);
        $bookShortage = max(0, $plan->reservedBooks - $this->bookCount($nextPlayer))
            - max(0, $plan->reservedBooks - $this->bookCount($player));
        $scholarShortage = max(0, $remainingPlan->reservedScholars - $nextPlayer->resources->scholars)
            - max(0, $plan->reservedScholars - $player->resources->scholars);

        $resourceDamage = max(0, $toolShortage) * self::TOOL_EFFORT_WEIGHT
            + max(0, $coinShortage) * self::COIN_EFFORT_WEIGHT
            + max(0, $annexShortage) * self::ANNEX_EFFORT_WEIGHT
            + max(0, $bookShortage) * self::BOOK_EFFORT_WEIGHT
            + max(0, $scholarShortage) * self::SCHOLAR_EFFORT_WEIGHT;

        $fundingProgress = $this->fundingProgress($before, $after, $player, $nextPlayer, $plan);

        $shippingOpportunityLoss = $this->shippingOpportunityLoss($before, $after, $player, $nextPlayer, $plan);

        $adjustment = max(-self::MAX_ACTION_ADJUSTMENT, min(
            self::MAX_ACTION_ADJUSTMENT,
            $progress + $fundingProgress - min(self::MAX_ACTION_ADJUSTMENT, $resourceDamage)
        )) - $shippingOpportunityLoss;

        return in_array($plan->objective, [StrategicObjective::FoundTown, StrategicObjective::ConnectNetwork], true)
            ? StrategicPriorities::settlementValue($before, $adjustment) : $adjustment;
    }

    private function shippingOpportunityLoss(GameStateData $before, GameStateData $after, GamePlayerStateData $player, GamePlayerStateData $nextPlayer, StrategicPlanData $plan): int
    {
        if ($plan->approach !== StrategicApproach::Shipping || $plan->targetLevel === null
            || $nextPlayer->shippingLevel !== $player->shippingLevel
            || $nextPlayer->resources->scholars >= $player->resources->scholars) {
            return 0;
        }
        $steps = max(0, $plan->targetLevel - $player->shippingLevel);
        if ($steps === 0) {
            return 0;
        }
        $rounds = min(2, max(0, 6 - $before->round->number));
        $income = PlayerIncomeCalculator::calculate($player, $before->board, includeRoundBonus: false);
        $nextIncome = PlayerIncomeCalculator::calculate($nextPlayer, $after->board, includeRoundBonus: false);
        $scholars = min($player->scholarPoolSize, $player->resources->scholars + $income->scholars * $rounds);
        $nextScholars = min($nextPlayer->scholarPoolSize, $nextPlayer->resources->scholars + $nextIncome->scholars * $rounds);
        $lostSteps = max(0, $steps - $nextScholars) - max(0, $steps - $scholars);
        if ($lostSteps <= 0 || $player->resources->coins + $income->coins * $rounds < $steps * 4) {
            return 0;
        }
        $targetPlayer = clone $player;
        $targetPlayer->shippingLevel = $plan->targetLevel;
        $networkGain = LargestNetworkSizeCalculator::calculate($targetPlayer, $before->board)
            - LargestNetworkSizeCalculator::calculate($player, $before->board);
        if ($networkGain <= 0) {
            return 0;
        }
        if ($this->fundingValidator->execute($after, $nextPlayer, $plan)) {
            return 0;
        }

        return min(self::STRATEGIC_COMPLETION_VALUE, intdiv(
            (self::STRATEGIC_COMPLETION_VALUE + $networkGain * 30) * $lostSteps,
            $steps,
        ));
    }

    private function fundingProgress(
        GameStateData $before,
        GameStateData $after,
        GamePlayerStateData $player,
        GamePlayerStateData $nextPlayer,
        StrategicPlanData $plan,
    ): int {
        $missingTools = max(0, $plan->reservedTools - $player->resources->tools);
        $missingCoins = max(0, $plan->reservedCoins - $player->resources->coins);
        $missingScholars = max(0, $plan->reservedScholars - $player->resources->scholars);
        $gainedTools = max(0, $nextPlayer->resources->tools - $player->resources->tools);
        $gainedCoins = max(0, $nextPlayer->resources->coins - $player->resources->coins);
        $gainedScholars = max(0, $nextPlayer->resources->scholars - $player->resources->scholars);
        if ($missingTools > $gainedTools && $before->round->number < 6) {
            $incomeBefore = PlayerIncomeCalculator::calculate($player, $before->board);
            $incomeAfter = PlayerIncomeCalculator::calculate($nextPlayer, $after->board);
            $gainedTools += max(0, $incomeAfter->tools - $incomeBefore->tools)
                * min(2, 6 - $before->round->number);
        }

        return min($missingTools, $gainedTools) * self::REQUIRED_TOOL_PROGRESS_WEIGHT
            + min($missingCoins, $gainedCoins) * self::REQUIRED_COIN_PROGRESS_WEIGHT
            + min($missingScholars, $gainedScholars) * self::SCHOLAR_EFFORT_WEIGHT;
    }

    private function remainingEffort(StrategicPlanData $plan): int
    {
        return $plan->remainingActions * self::ACTION_EFFORT_WEIGHT
            + $plan->reservedTools * self::TOOL_EFFORT_WEIGHT
            + $plan->reservedCoins * self::COIN_EFFORT_WEIGHT
            + $plan->requiredAnnexes * self::ANNEX_EFFORT_WEIGHT
            + $plan->reservedBooks * self::BOOK_EFFORT_WEIGHT
            + $plan->reservedScholars * self::SCHOLAR_EFFORT_WEIGHT;
    }

    private function innovationPlan(GameStateData $state, GamePlayerStateData $player): ?StrategicPlanData
    {
        if ($state->setupPool === null || count($player->inventionIds) >= InnovationPurchaseCostCalculator::MAX_INVENTIONS) {
            return null;
        }
        $best = null;
        $futureBooks = $this->futureBookIncome($state, $player);
        foreach ($state->setupPool->innovations as $slot => $value) {
            $innovation = $this->innovation($value);
            if (! in_array($innovation->value, $state->availableInventionIds, true)) {
                continue;
            }
            $cost = $this->innovationCostCalculator->cost(
                playerCount: $state->setupPool->playerCount,
                slotIndex: $slot,
                ownedInventionCount: count($player->inventionIds),
                skipsSecondInventionSurcharge: $player->homeland === TerrainType::Wasteland,
                hasPalace: $player->palaceId !== null,
            );
            $score = self::STRATEGIC_COMPLETION_VALUE + $this->innovationEvaluator->purchasePotential($state, $player, $innovation)
                - $cost['totalBooks'] * self::BOOK_EFFORT_WEIGHT - $cost['coins'] * self::COIN_EFFORT_WEIGHT;
            $candidate = new StrategicPlanData(
                StrategicObjective::MakeInnovation,
                [],
                [],
                0,
                $cost['coins'],
                0,
                $score,
                1,
                $innovation->value,
                $cost['totalBooks'],
                approach: StrategicApproach::Innovation,
                requiredBooks: new BookPaymentData(...$cost['requiredBooks']),
            );
            if ($this->fulfilledInnovationBooks($player, $candidate) + $player->resources->books->unassigned + $futureBooks < $cost['totalBooks']) {
                continue;
            }
            if ($best === null || $candidate->score > $best->score) {
                $best = $candidate;
            }
        }

        return $best;
    }

    private function palacePlan(GameStateData $state, GamePlayerStateData $player): ?StrategicPlanData
    {
        if ($player->palaceId !== null || $state->availablePalaceIds === [] || $state->round->number >= 6) {
            return null;
        }
        $guild = collect($state->board->hexes)->first(static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $player->playerId && ! $hex->building->isNeutral
            && $hex->building->type === BuildingType::Guild);
        if (! $guild instanceof BoardHexStateData) {
            return null;
        }
        $bestAbility = null;
        $bestValue = 0;
        foreach ($state->availablePalaceIds as $palaceId) {
            $ability = PalaceAbility::tryFrom($palaceId);
            if ($ability === null) {
                continue;
            }
            $probe = clone $player;
            $probe->palaceId = $ability->value;
            $value = $this->palaceAbilityEvaluator->execute($state, $probe);
            if ($value > $bestValue) {
                $bestValue = $value;
                $bestAbility = $ability;
            }
        }
        if ($bestAbility === null || $bestValue === 0) {
            return null;
        }
        $cost = BuildingType::Guild->upgradeCostTo(BuildingType::Palace);

        return new StrategicPlanData(
            StrategicObjective::BuildPalace,
            [$guild->id],
            [],
            $cost['tools'],
            $cost['coins'],
            0,
            self::STRATEGIC_COMPLETION_VALUE + $bestValue - $cost['tools'] * self::TOOL_EFFORT_WEIGHT - $cost['coins'] * self::COIN_EFFORT_WEIGHT,
            1,
            $bestAbility->value,
            approach: StrategicApproach::Palace,
        );
    }

    /** @param list<list<BoardHexStateData>> $components */
    private function networkPlan(GameStateData $state, GamePlayerStateData $player, array $components): ?StrategicPlanData
    {
        if (count($components) < 2) {
            return null;
        }
        $componentByHex = [];
        foreach ($components as $index => $component) {
            foreach ($component as $hex) {
                $componentByHex[$hex->id] = $index;
            }
        }
        $best = $this->networkConnectionPlanner->execute($state, $player, $components);

        if ($this->bridgeSupply->remaining($state, $player) > 0) {
            foreach ($this->findEligibleBridgePairs->execute($state, $player->playerId) as $pair) {
                $fromComponent = $componentByHex[$pair['fromHexId']] ?? null;
                $toComponent = $componentByHex[$pair['toHexId']] ?? null;
                if ($fromComponent === null || $toComponent === null || $fromComponent === $toComponent) {
                    continue;
                }
                $fromTown = array_any($components[$fromComponent], static fn (BoardHexStateData $hex): bool => $hex->townId !== null);
                $toTown = array_any($components[$toComponent], static fn (BoardHexStateData $hex): bool => $hex->townId !== null);
                if ($state->round->number < 6 && $fromTown !== $toTown) {
                    continue;
                }
                $anchors = array_values(array_unique([
                    ...array_map(static fn (BoardHexStateData $hex): string => $hex->id, $components[$fromComponent]),
                    ...array_map(static fn (BoardHexStateData $hex): string => $hex->id, $components[$toComponent]),
                ]));
                sort($anchors, SORT_STRING);
                $networkSize = count($components[$fromComponent]) + count($components[$toComponent]);
                $candidate = new StrategicPlanData(
                    StrategicObjective::ConnectNetwork,
                    $anchors,
                    [],
                    0,
                    0,
                    0,
                    self::STRATEGIC_COMPLETION_VALUE + $networkSize * 20,
                    1,
                    $pair['fromHexId'],
                    approach: StrategicApproach::Bridge,
                    secondaryTargetId: $pair['toHexId'],
                );
                if (($best === null || $candidate->score > $best->score)
                    && $this->fundingValidator->execute($state, $player, $candidate)) {
                    $best = $candidate;
                }
            }
        }

        $completedTownComponents = count(array_filter($components, static fn (array $component): bool =>
            array_any($component, static fn (BoardHexStateData $hex): bool => $hex->townId !== null)));
        if ($player->shippingLevel < 3 && ($state->round->number >= 6 || $completedTownComponents === 0 || $completedTownComponents >= 2)) {
            $beforeNetwork = LargestNetworkSizeCalculator::calculate($player, $state->board);
            $shippingPlayer = clone $player;
            for ($level = $player->shippingLevel + 1; $level <= 3; $level++) {
                $shippingPlayer->shippingLevel = $level;
                $networkGain = LargestNetworkSizeCalculator::calculate($shippingPlayer, $state->board) - $beforeNetwork;
                if ($networkGain <= 0) {
                    continue;
                }
                $steps = $level - $player->shippingLevel;
                if (! $this->canStartShippingChain($state, $player, $steps, $completedTownComponents)) {
                    continue;
                }
                $candidate = new StrategicPlanData(
                    StrategicObjective::ConnectNetwork,
                    [],
                    [],
                    0,
                    $steps * 4,
                    0,
                    self::STRATEGIC_COMPLETION_VALUE + $networkGain * 30 - $steps * (4 * self::COIN_EFFORT_WEIGHT + self::SCHOLAR_EFFORT_WEIGHT),
                    $steps,
                    null,
                    reservedScholars: $steps,
                    approach: StrategicApproach::Shipping,
                    targetLevel: $shippingPlayer->shippingLevel,
                );
                if (($best === null || $candidate->score > $best->score)
                    && $this->fundingValidator->execute($state, $player, $candidate)) {
                    $best = $candidate;
                }
            }
        }

        return $best;
    }

    private function sameIntention(StrategicPlanData $first, StrategicPlanData $second): bool
    {
        if ($first->objective !== $second->objective || $first->approach !== $second->approach
            || $first->targetId !== $second->targetId || $first->secondaryTargetId !== $second->secondaryTargetId
            || $first->targetLevel !== $second->targetLevel) {
            return false;
        }

        return $first->anchorHexIds === [] || array_diff($first->anchorHexIds, $second->anchorHexIds) === [];
    }

    private function canStartShippingChain(GameStateData $state, GamePlayerStateData $player, int $steps, int $completedTownComponents): bool
    {
        return $steps <= 1 || $state->round->number >= 5 || $completedTownComponents >= 2
            || ($player->resources->scholars >= $steps && $player->resources->coins >= $steps * 4);
    }

    private function updatedTownPlan(GameStateData $state, GamePlayerStateData $player, StrategicPlanData $previous): ?StrategicPlanData
    {
        foreach ($this->componentFinder->execute($state, $player->playerId) as $component) {
            $anchors = array_values(array_map(static fn (BoardHexStateData $hex): string => $hex->id, array_filter(
                $component,
                static fn (BoardHexStateData $hex): bool => ! $hex->building->isNeutral
            )));
            if (array_diff($previous->anchorHexIds, $anchors) !== []
                || array_any($component, static fn (BoardHexStateData $hex): bool => $hex->townId !== null)) {
                continue;
            }
            sort($anchors, SORT_STRING);
            $planning = $this->townPlanner->execute($state, $player, [$component], [0], compareDevelopmentEconomy: true);
            $existingTownIds = [];
            foreach ($state->board->hexes as $hex) {
                if ($hex->building?->ownerPlayerId === $player->playerId && $hex->townId !== null) {
                    $existingTownIds[$hex->townId] = true;
                }
            }
            if ($planning->projectedTowns <= count($existingTownIds)) {
                return null;
            }

            return new StrategicPlanData(
                StrategicObjective::FoundTown,
                $anchors,
                $planning->expansionHexIds,
                $planning->reservedTools,
                $planning->reservedCoins,
                $planning->requiredAnnexes,
                StrategicPriorities::settlementValue($state, $planning->score),
                $planning->remainingActions,
                approach: StrategicApproach::TownDevelopment,
                plannedRound: $state->round->number,
            );
        }

        return null;
    }

    private function revalidateCurrentPlan(GameStateData $state, GamePlayerStateData $player, StrategicPlanData $plan): ?StrategicPlanData
    {
        if ($plan->objective === StrategicObjective::FoundTown) {
            return $this->updatedTownPlan($state, $player, $plan);
        }
        if ($plan->objective === StrategicObjective::MakeInnovation) {
            return $plan->targetId !== null
                && ! in_array($plan->targetId, $player->inventionIds, true)
                && in_array($plan->targetId, $state->availableInventionIds, true)
                && $plan->requiredBooks !== null && $this->fulfilledInnovationBooks($player, $plan)
                    + $player->resources->books->unassigned + $this->futureBookIncome($state, $player) >= $plan->reservedBooks ? $plan : null;
        }
        if ($plan->objective === StrategicObjective::BuildPalace) {
            $hasGuild = collect($state->board->hexes)->contains(static fn (BoardHexStateData $hex): bool =>
                in_array($hex->id, $plan->anchorHexIds, true)
                && $hex->building?->ownerPlayerId === $player->playerId
                && $hex->building->type === BuildingType::Guild);

            return $player->palaceId === null && $plan->targetId !== null
                && in_array($plan->targetId, $state->availablePalaceIds, true) && $hasGuild ? $plan : null;
        }
        if ($plan->approach === StrategicApproach::Shipping) {
            if ($plan->targetLevel === null || $player->shippingLevel >= $plan->targetLevel) {
                return null;
            }
            $updated = clone $plan;
            $steps = $plan->targetLevel - $player->shippingLevel;
            $completedTownComponents = count(array_filter(
                $this->componentFinder->execute($state, $player->playerId),
                static fn (array $component): bool => array_any($component, static fn (BoardHexStateData $hex): bool => $hex->townId !== null)
            ));
            $targetPlayer = clone $player;
            $targetPlayer->shippingLevel = $plan->targetLevel;
            if (! $this->canStartShippingChain($state, $player, $steps, $completedTownComponents)
                || LargestNetworkSizeCalculator::calculate($targetPlayer, $state->board)
                    <= LargestNetworkSizeCalculator::calculate($player, $state->board)) {
                return null;
            }
            $updated->reservedCoins = $steps * 4;
            $updated->reservedScholars = $steps;
            $updated->remainingActions = $steps;

            return $updated;
        }
        if ($plan->approach === StrategicApproach::Bridge) {
            $owned = collect($state->board->hexes)->filter(static fn (BoardHexStateData $hex): bool =>
                $hex->building?->ownerPlayerId === $player->playerId)->pluck('id')->all();

            return $plan->targetId !== null && $plan->secondaryTargetId !== null
                && in_array($plan->targetId, $owned, true) && in_array($plan->secondaryTargetId, $owned, true) ? $plan : null;
        }
        if ($plan->approach === StrategicApproach::Build) {
            return $this->updatedNetworkRoute($state, $player, $plan);
        }

        return null;
    }

    private function updatedNetworkRoute(GameStateData $state, GamePlayerStateData $player, StrategicPlanData $plan): ?StrategicPlanData
    {
        $hexes = collect($state->board->hexes)->keyBy('id');
        $route = [];
        $tools = 0;
        foreach ($plan->expansionHexIds as $id) {
            $hex = $hexes->get($id);
            if (! $hex instanceof BoardHexStateData || ! $hex->terrain->isHomeland()) {
                return null;
            }
            if ($hex->building !== null) {
                if ($hex->building->ownerPlayerId !== $player->playerId) {
                    return null;
                }
                continue;
            }
            $route[] = $id;
            $tools += 1 + $hex->terrain->spadesTo($player->homeland) * max(1, 3 - $player->terraformingLevel);
        }
        if ($route === [] || array_any($plan->anchorHexIds, static fn (string $id): bool => $hexes->get($id)?->building?->ownerPlayerId !== $player->playerId)) {
            return null;
        }
        $workshops = count(array_filter($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
            $hex->building?->ownerPlayerId === $player->playerId && ! $hex->building->isNeutral
            && $hex->building->type === BuildingType::Workshop));
        $income = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $incomeRounds = min(2, max(0, 6 - $state->round->number));
        if (count($route) > BuildingType::Workshop->supplyLimit() - $workshops
            || $tools > $player->resources->tools + $income->tools * $incomeRounds
            || count($route) * 2 > $player->resources->coins + $income->coins * $incomeRounds
            || ($state->round->number < 6
                && array_any($plan->anchorHexIds, static fn (string $id): bool => $hexes->get($id)?->townId !== null)
                && array_any($plan->anchorHexIds, static fn (string $id): bool => $hexes->get($id)?->townId === null))) {
            return null;
        }
        $updated = clone $plan;
        $updated->expansionHexIds = $route;
        $updated->reservedTools = $tools;
        $updated->reservedCoins = count($route) * 2;
        $updated->remainingActions = count($route);

        return $updated;
    }

    private function bookCount(GamePlayerStateData $player): int
    {
        $books = $player->resources->books;

        return $books->banking + $books->law + $books->engineering + $books->medicine + $books->unassigned;
    }

    private function fulfilledInnovationBooks(GamePlayerStateData $player, StrategicPlanData $plan): int
    {
        if ($plan->requiredBooks === null) {
            return 0;
        }
        $fulfilled = 0;
        $assigned = 0;
        foreach ($plan->requiredBooks->counts() as $discipline => $required) {
            $available = $player->resources->books->{$discipline};
            $fulfilled += min($available, $required);
            $assigned += $available;
        }
        $genericSlots = max(0, $plan->reservedBooks - array_sum($plan->requiredBooks->counts()));

        return $fulfilled + min($genericSlots, max(0, $assigned - $fulfilled));
    }

    private function futureBookIncome(GameStateData $state, GamePlayerStateData $player): int
    {
        $books = PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false)->books
            * max(0, 6 - $state->round->number);
        foreach ($state->setupPool->roundScoringTiles ?? [] as $index => $tile) {
            if ($index + 1 < $state->round->number || $index + 1 >= 6) {
                continue;
            }
            $tile = is_string($tile) ? RoundScoringTile::from($tile) : $tile;
            $books += $tile->scienceBonus($player->knowledge->{$tile->knowledgeDiscipline()->value})['books'];
        }

        return $books;
    }

    /** @param list<BoardHexStateData> $component */
    private function hasVacantExpansionSpace(GameStateData $state, array $component): bool
    {
        $componentIds = array_fill_keys(array_map(static fn (BoardHexStateData $hex): string => $hex->id, $component), true);

        return array_any($state->board->hexes, static fn (BoardHexStateData $hex): bool =>
            $hex->building === null && $hex->terrain->isHomeland()
            && array_any($hex->adjacentHexIds, static fn (string $id): bool => isset($componentIds[$id])));
    }

    private function innovation(mixed $value): Innovation
    {
        return $value instanceof Innovation ? $value : Innovation::from((string) $value);
    }
}
