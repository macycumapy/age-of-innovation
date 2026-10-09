<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Data\EvaluatedGameActionData;
use App\Domain\Automation\Data\GameActionScoreData;
use App\Domain\Automation\Data\GameActionSearchTimingsData;
use App\Domain\Automation\Data\GameActionSimulationData;
use App\Domain\Automation\Data\GameActionSimulationTimingsData;
use App\Domain\Automation\Data\GameTreeSearchContext;
use App\Domain\Automation\Evaluation\Data\GameStateScoreData;
use App\Domain\Automation\Evaluation\Services\BoardPositionProgressEvaluator;
use App\Domain\Automation\Evaluation\Services\FinalScoringProgressEvaluator;
use App\Domain\Automation\Evaluation\Services\GameStateEvaluator;
use App\Domain\Automation\Evaluation\Services\PassValueEvaluator;
use App\Domain\Automation\Evaluation\Services\PlayerEconomicNeedsEvaluator;
use App\Domain\Automation\Evaluation\Services\RoundScoringProgressEvaluator;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Economy\Data\PowerActionOptionData;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Actions\ApplyFinishActionTurnAction;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Domain\GameEngine\Turns\Services\PassOptionFinder;
use InvalidArgumentException;
use Spatie\LaravelData\Data;

final class GameActionRanker
{
    private int $lastVisitedNodes = 0;

    private bool $lastBudgetExhausted = false;

    private ?GameActionSearchTimingsData $lastSearchTimings = null;

    private const int MAX_AUXILIARY_ACTIONS_PER_TURN = 3;

    private const int ROUND_SCORING_PRIORITY_WEIGHT = 10;

    private const int FINAL_SCORING_PRIORITY_WEIGHT = 10;

    private const int HORIZON_REWARD_BRANCH_LIMIT = 2;

    public function __construct(
        private GameActionOptionFinder $gameActionOptionFinder,
        private GameActionSimulator $gameActionSimulator,
        private GameStateEvaluator $gameStateEvaluator,
        private RoundScoringProgressEvaluator $roundScoringProgressEvaluator,
        private FinalScoringProgressEvaluator $finalScoringProgressEvaluator,
        private BoardPositionProgressEvaluator $boardPositionProgressEvaluator,
        private PassValueEvaluator $passValueEvaluator,
        private PlayerEconomicNeedsEvaluator $playerEconomicNeedsEvaluator,
        private ApplyFinishActionTurnAction $applyFinishActionTurn,
        private PassOptionFinder $passOptionFinder,
    ) {
    }

    /** @return list<EvaluatedGameActionData> */
    public function execute(
        GameStateData $state,
        int $playerId,
        int $depth = 1,
        int $branchLimit = 8,
        int $maxNodes = 1000,
        int $maxTimeMilliseconds = 20_000,
        int $auxiliaryActionsRemaining = self::MAX_AUXILIARY_ACTIONS_PER_TURN,
        ?GameActionOption $requiredRootOption = null,
    ): array {
        if ($depth < 1
            || $branchLimit < 1
            || $maxNodes < 1
            || $maxTimeMilliseconds < 1
            || $auxiliaryActionsRemaining < 0
        ) {
            throw new InvalidArgumentException('Глубина, ширина и бюджеты поиска должны быть положительными.');
        }

        $context = new GameTreeSearchContext($maxNodes, $maxTimeMilliseconds);
        if ($depth > 1) {
            $context->fundingForecastNodeLimit = max(1, intdiv($maxNodes, 3));
        }
        $candidates = [];

        $options = $this->findOptions($state, $playerId, $context);
        if ($auxiliaryActionsRemaining === 0) {
            $options = array_values(array_filter(
                $options,
                fn (GameActionOption $option): bool => ! $this->isAuxiliaryOption($option),
            ));
        }
        $hasNonPassOption = $this->hasNonPassOption($options);
        $availableOptionKeys = array_fill_keys(array_map($this->optionCacheKey(...), $options), true);

        foreach ($options as $index => $option) {
            if ($candidates !== [] && $context->isExhausted()) {
                break;
            }

            $simulation = $this->simulate($state, $playerId, $option, $context);
            $passPenalty = $this->passPenalty($state, $playerId, $option, $hasNonPassOption);
            $scoreBreakdown = $this->scoreBreakdown($state, $simulation->state, $playerId, $passPenalty, $context);
            $candidates[] = [
                'option' => $option,
                'simulation' => $simulation,
                'score' => $scoreBreakdown->total(),
                'index' => $index,
                'isPass' => $option->type() === GameActionOptionType::Pass,
                'scoreBreakdown' => $scoreBreakdown,
                'fundedOptions' => $state->pendingInteraction === null && $state->round->phase === GamePhase::Actions
                    && ! $state->round->hasTakenMainAction && $this->isAuxiliaryOption($option)
                    ? $this->newlyFundedOptions($simulation->state, $playerId, $availableOptionKeys, $context)
                    : [],
            ];
        }

        usort(
            $candidates,
            static fn (array $left, array $right): int => $right['score'] <=> $left['score']
                ?: $left['isPass'] <=> $right['isPass']
                ?: $left['index'] <=> $right['index'],
        );

        $rankedActions = [];
        foreach ($candidates as $candidate) {
            $rankedActions[] = [
                'evaluation' => new EvaluatedGameActionData(
                    $candidate['option'],
                    $candidate['simulation'],
                    $candidate['score'],
                    $candidate['scoreBreakdown'],
                ),
                'index' => $candidate['index'],
                'isAuxiliary' => $this->isAuxiliaryOption($candidate['option']),
                'isPass' => $candidate['isPass'],
            ];
        }

        $rewardForecastIndexes = array_keys($candidates);
        $fundingPlans = [];
        if ($depth > 1 && $state->pendingInteraction === null && $state->round->phase === GamePhase::Actions) {
            $rewardForecastIndexes = $this->rootSearchIndexes($rankedActions, $branchLimit * 2, $requiredRootOption);
            $protectedInteractions = [];
            foreach ($candidates as $index => $candidate) {
                $interactionType = $candidate['simulation']->state->pendingInteraction?->type->value;
                if ($interactionType !== null && ! isset($protectedInteractions[$interactionType])) {
                    $protectedInteractions[$interactionType] = true;
                    if (! in_array($index, $rewardForecastIndexes, true)) {
                        $rewardForecastIndexes[] = $index;
                    }
                }
            }
        }
        usort($rewardForecastIndexes, fn (int $left, int $right): int =>
            ($candidates[$left]['fundedOptions'] !== [] ? 1 : $this->rewardForecastPriority($candidates[$left]['simulation']->state))
                <=> ($candidates[$right]['fundedOptions'] !== [] ? 1 : $this->rewardForecastPriority($candidates[$right]['simulation']->state))
                    ?: $left <=> $right);

        for ($searchDepth = 0; $searchDepth <= $depth && ! $context->isExhausted(); $searchDepth++) {
            if ($searchDepth === 1 && $state->pendingInteraction === null) {
                continue;
            }
            $iterationActions = [];
            $iterationBestScore = PHP_INT_MIN;
            $searchIndexes = $searchDepth === 0 ? $rewardForecastIndexes : $this->rootSearchIndexes($rankedActions, $branchLimit, $requiredRootOption);
            foreach ($searchIndexes as $candidateIndex) {
                $candidate = $candidates[$candidateIndex];
                if ($context->isExhausted()) {
                    break;
                }
                $option = $candidate['option'];
                $simulation = $candidate['simulation'];
                $initialBreakdown = $candidate['scoreBreakdown'];
                $searchStartedAt = hrtime(true);
                $requiresFundingPlan = $state->pendingInteraction === null
                    && $state->round->phase === GamePhase::Actions && $this->isAuxiliaryOption($option);
                if ($searchDepth > 0 && $requiresFundingPlan && ! isset($fundingPlans[$candidateIndex])) {
                    $iterationActions[$candidateIndex] = $rankedActions[$candidateIndex];

                    continue;
                }
                $fundingPlan = $searchDepth === 0 && $requiresFundingPlan
                    ? $this->fundedActionForecast(
                        $simulation->state,
                        $playerId,
                        $candidate['fundedOptions'],
                        $availableOptionKeys,
                        $auxiliaryActionsRemaining - 1,
                        $context,
                    ) : ($fundingPlans[$candidateIndex] ?? null);
                if ($fundingPlan !== null) {
                    $fundingPlans[$candidateIndex] = $fundingPlan;
                }
                $searchScore = $fundingPlan !== null ? $fundingPlan->score : $this->search(
                    new GameActionSimulationData($simulation->state->deepCopy(), $simulation->nextActivePlayerId),
                    $playerId,
                    $searchDepth === 0 ? 0 : $this->remainingDepthAfter($state, $option, $searchDepth),
                    $this->auxiliaryActionsAfter($option, $auxiliaryActionsRemaining),
                    $branchLimit,
                    $context,
                    $searchDepth === 0 ? PHP_INT_MIN : $this->relativeSearchBound(
                        $iterationBestScore,
                        $initialBreakdown->total() - $initialBreakdown->state->total(),
                    ),
                    PHP_INT_MAX,
                );
                $context->timings->continuationSearchNanoseconds += hrtime(true) - $searchStartedAt;
                $scoreBreakdown = new GameActionScoreData(
                    state: $initialBreakdown->state,
                    roundScoring: $initialBreakdown->roundScoring,
                    finalScoring: $initialBreakdown->finalScoring,
                    boardPosition: $initialBreakdown->boardPosition,
                    economicNeeds: $initialBreakdown->economicNeeds,
                    passPenalty: $initialBreakdown->passPenalty,
                    searchAdjustment: $searchScore - $initialBreakdown->state->total()
                        - ($fundingPlan !== null ? max(0, $initialBreakdown->economicNeeds) : 0),
                );
                $iterationActions[$candidateIndex] = [
                    'evaluation' => new EvaluatedGameActionData(
                        $option,
                        $simulation,
                        $scoreBreakdown->total(),
                        $scoreBreakdown,
                        $fundingPlan === null ? [] : [...$fundingPlan->continuation, $fundingPlan->option],
                    ),
                    'index' => $candidate['index'],
                    'isAuxiliary' => $this->isAuxiliaryOption($option),
                    'isPass' => $option->type() === GameActionOptionType::Pass,
                ];
                $iterationBestScore = max($iterationBestScore, $scoreBreakdown->total());
                if ($searchDepth === 0 && (! $context->isExhausted() || $fundingPlan !== null)) {
                    $rankedActions[$candidateIndex] = $iterationActions[$candidateIndex];
                }
            }

            if ($context->isExhausted() || count($iterationActions) !== count($searchIndexes)) {
                break;
            }

            $rankedActions = $iterationActions;
            $context->timings->completedSearchDepth = $searchDepth === 0 && $state->pendingInteraction === null ? 1 : $searchDepth;
        }

        $bestDirectScore = null;
        foreach ($rankedActions as $action) {
            if (! $action['isAuxiliary']) {
                $bestDirectScore = $bestDirectScore === null ? $action['evaluation']->score : max($bestDirectScore, $action['evaluation']->score);
            }
        }
        if ($state->pendingInteraction === null && $state->round->phase === GamePhase::Actions && $bestDirectScore !== null) {
            foreach ($rankedActions as &$action) {
                if (! $action['isAuxiliary'] || $action['evaluation']->continuation !== [] || $action['evaluation']->score < $bestDirectScore) {
                    continue;
                }
                $evaluation = $action['evaluation'];
                $breakdown = $evaluation->scoreBreakdown;
                $score = new GameActionScoreData(
                    state: $breakdown->state,
                    roundScoring: $breakdown->roundScoring,
                    finalScoring: $breakdown->finalScoring,
                    boardPosition: $breakdown->boardPosition,
                    economicNeeds: $breakdown->economicNeeds,
                    passPenalty: $breakdown->passPenalty,
                    searchAdjustment: $breakdown->searchAdjustment + $bestDirectScore - 1 - $evaluation->score,
                );
                $action['evaluation'] = new EvaluatedGameActionData($evaluation->option, $evaluation->simulation, $score->total(), $score);
            }
            unset($action);
        }

        usort(
            $rankedActions,
            static fn (array $left, array $right): int => $right['evaluation']->score <=> $left['evaluation']->score
                ?: $left['isPass'] <=> $right['isPass']
                ?: $left['isAuxiliary'] <=> $right['isAuxiliary']
                ?: $left['index'] <=> $right['index'],
        );

        $this->lastVisitedNodes = $context->visitedNodes;
        $this->lastSearchTimings = $context->timings;
        $this->lastBudgetExhausted = $context->isExhausted();

        return array_column($rankedActions, 'evaluation');
    }

    public function lastVisitedNodes(): int
    {
        return $this->lastVisitedNodes;
    }

    private function rewardForecastPriority(GameStateData $state): int
    {
        $interaction = $state->pendingInteraction;
        if ($interaction?->type === PendingInteractionType::PowerOffer
            && in_array($interaction->context['buildingFollowUpType'] ?? null, [
                BuildingType::Palace->value, BuildingType::School->value, BuildingType::University->value,
            ], true)) {
            return 0;
        }

        return match ($interaction?->type) {
            PendingInteractionType::ChoosePalace,
            PendingInteractionType::ChooseCompetency,
            PendingInteractionType::ChooseTown => 0,
            PendingInteractionType::PowerOffer => 1,
            null => 3,
            default => 2,
        };
    }

    public function lastBudgetExhausted(): bool
    {
        return $this->lastBudgetExhausted;
    }

    public function lastSearchTimings(): GameActionSearchTimingsData
    {
        return $this->lastSearchTimings ?? new GameActionSearchTimingsData();
    }

    /**
     * @param array<int, array{evaluation: EvaluatedGameActionData, index: int, isAuxiliary: bool, isPass: bool}> $actions
     * @return list<int>
     */
    private function rootSearchIndexes(array $actions, int $branchLimit, ?GameActionOption $requiredOption): array
    {
        $eligible = array_filter($actions, static fn (array $action): bool =>
            ! $action['isAuxiliary'] || $action['evaluation']->continuation !== []);
        uasort($eligible, static fn (array $left, array $right): int =>
            $right['evaluation']->score <=> $left['evaluation']->score
                ?: $left['isPass'] <=> $right['isPass']
                ?: $left['index'] <=> $right['index']);
        $indexes = array_slice(array_keys($eligible), 0, $branchLimit);
        foreach ($eligible as $index => $action) {
            if ($action['isPass'] && ! in_array($index, $indexes, true)) {
                $indexes[] = $index;
                break;
            }
        }
        foreach ($actions as $index => $action) {
            if (($action['isAuxiliary'] || ($requiredOption !== null
                    && $action['evaluation']->option->type() === $requiredOption->type()
                    && $action['evaluation']->option->toArray() === $requiredOption->toArray()))
                && ! in_array($index, $indexes, true)) {
                $indexes[] = $index;
            }
        }

        return $indexes;
    }

    private function search(
        GameActionSimulationData $simulation,
        int $rootPlayerId,
        int $remainingDepth,
        int $auxiliaryActionsRemaining,
        int $branchLimit,
        GameTreeSearchContext $context,
        int $alpha,
        int $beta,
    ): int {
        $state = $simulation->state;
        $nextActivePlayerId = $simulation->nextActivePlayerId;

        if ($state->round->phase->isActionPhase()
            && $state->pendingInteraction === null
            && $state->round->hasTakenMainAction) {
            $currentPlayer = $this->playerById($state, $nextActivePlayerId);

            if ($currentPlayer === null) {
                return $this->evaluateState($state, $rootPlayerId, $context)->total();
            }

            $nextPlayerId = $this->applyFinishActionTurn->execute($state, $currentPlayer);
            $nextPlayer = collect($state->players)->firstWhere('playerId', $nextPlayerId);
            $nextActivePlayerId = $nextPlayer instanceof GamePlayerStateData ? $nextPlayer->playerId : null;
            $auxiliaryActionsRemaining = self::MAX_AUXILIARY_ACTIONS_PER_TURN;
        }

        if ($remainingDepth === 0) {
            return $this->horizonScore($state, $rootPlayerId, $context);
        }

        $cacheKey = $this->cacheKey(
            $state,
            $nextActivePlayerId,
            $rootPlayerId,
            $remainingDepth,
            $auxiliaryActionsRemaining,
        );
        if (isset($context->cachedScores[$cacheKey])) {
            return $context->cachedScores[$cacheKey];
        }

        if ($context->isExhausted()) {
            return $this->evaluateState($state, $rootPlayerId, $context)->total();
        }

        $context->visitedNodes++;

        $activePlayer = $this->playerById($state, $nextActivePlayerId);
        if ($activePlayer === null) {
            return $this->evaluateState($state, $rootPlayerId, $context)->total();
        }

        $options = $this->findOptions($state, $activePlayer->playerId, $context);
        if ($auxiliaryActionsRemaining === 0) {
            $options = array_values(array_filter(
                $options,
                fn (GameActionOption $option): bool => ! $this->isAuxiliaryOption($option),
            ));
        }
        $hasNonPassOption = $this->hasNonPassOption($options);
        $simulations = [];
        $maximizing = $activePlayer->playerId === $rootPlayerId;
        foreach ($options as $option) {
            if ($simulations !== [] && $context->isExhausted()) {
                break;
            }

            $nextSimulation = $this->simulate($state, $activePlayer->playerId, $option, $context);
            $passPenalty = $this->passPenalty(
                $state,
                $activePlayer->playerId,
                $option,
                $hasNonPassOption,
            );
            $breakdown = $this->scoreBreakdown($state, $nextSimulation->state, $rootPlayerId, 0, $context);
            $progressScore = $breakdown->total() - $breakdown->state->total();
            $simulations[] = [
                'simulation' => $nextSimulation,
                'score' => $breakdown->total()
                    + ($maximizing ? -$passPenalty : $passPenalty),
                'remainingDepth' => $this->remainingDepthAfter($state, $option, $remainingDepth),
                'auxiliaryActionsRemaining' => $this->auxiliaryActionsAfter($option, $auxiliaryActionsRemaining),
                'passPenalty' => $passPenalty,
                'progressScore' => $progressScore,
                'isAuxiliary' => $this->isAuxiliaryOption($option),
            ];
        }

        if ($simulations === []) {
            return $this->evaluateState($state, $rootPlayerId, $context)->total();
        }

        usort(
            $simulations,
            static fn (array $left, array $right): int => $maximizing
                ? $right['score'] <=> $left['score']
                : $left['score'] <=> $right['score'],
        );
        $mainSimulations = array_filter($simulations, static fn (array $candidate): bool => ! $candidate['isAuxiliary']);
        $auxiliarySimulations = array_filter($simulations, static fn (array $candidate): bool => $candidate['isAuxiliary']);
        $simulations = [...array_slice($mainSimulations, 0, $branchLimit), ...array_slice($auxiliarySimulations, 0, 1)];
        $bestScore = $maximizing ? PHP_INT_MIN : PHP_INT_MAX;
        $wasCutOff = false;
        $initialAlpha = $alpha;
        $initialBeta = $beta;

        foreach ($simulations as $candidate) {
            $progressScore = $candidate['progressScore'] + ($maximizing ? -$candidate['passPenalty'] : $candidate['passPenalty']);
            $score = $this->search(
                $candidate['simulation'],
                $rootPlayerId,
                $candidate['remainingDepth'],
                $candidate['auxiliaryActionsRemaining'],
                $branchLimit,
                $context,
                $this->relativeSearchBound($alpha, $progressScore),
                $this->relativeSearchBound($beta, $progressScore),
            ) + $progressScore;

            if ($maximizing) {
                $bestScore = max($bestScore, $score);
                $alpha = max($alpha, $score);
            } else {
                $bestScore = min($bestScore, $score);
                $beta = min($beta, $score);
            }

            if ($beta <= $alpha) {
                $wasCutOff = true;

                break;
            }
        }

        if (! $wasCutOff && ! $context->isExhausted() && $bestScore > $initialAlpha && $bestScore < $initialBeta) {
            $context->cachedScores[$cacheKey] = $bestScore;
        }

        return $bestScore;
    }

    private function relativeSearchBound(int $bound, int $progressScore): int
    {
        return match ($bound) {
            PHP_INT_MIN, PHP_INT_MAX => $bound,
            default => $bound - $progressScore,
        };
    }

    private function horizonScore(GameStateData $state, int $rootPlayerId, GameTreeSearchContext $context): int
    {
        $interaction = $state->pendingInteraction;
        if ($context->isExhausted()) {
            return $this->evaluateState($state, $rootPlayerId, $context)->total();
        }
        if ($interaction === null) {
            return $this->finalSchoolPassForecast($state, $rootPlayerId, $context);
        }

        $cacheKey = $this->cacheKey($state, $interaction->playerId, $rootPlayerId, 0, 0);
        if (isset($context->cachedScores[$cacheKey])) {
            return $context->cachedScores[$cacheKey];
        }

        $player = $this->playerById($state, $interaction->playerId);
        if ($player === null) {
            return $this->evaluateState($state, $rootPlayerId, $context)->total();
        }

        if (! in_array($interaction->type, [
            PendingInteractionType::PowerOffer,
            PendingInteractionType::SpendSpades,
            PendingInteractionType::BuildWorkshopAfterTerraforming,
            PendingInteractionType::ChooseCompetency,
            PendingInteractionType::ChoosePalace,
            PendingInteractionType::ChoosePalaceRewardOrder,
            PendingInteractionType::ChooseTown,
            PendingInteractionType::OfferPalaceWaterTown,
            PendingInteractionType::PlacePalaceGuild,
            PendingInteractionType::PlaceNeutralBuilding,
            PendingInteractionType::PlaceBridge,
            PendingInteractionType::ChooseTownBooks,
            PendingInteractionType::ChooseFelineTownBonus,
            PendingInteractionType::ChooseShippingBooks,
            PendingInteractionType::ChooseTerraformingBooks,
            PendingInteractionType::ChoosePalaceBooks,
            PendingInteractionType::ChooseInnovationReward,
            PendingInteractionType::ChooseRoundBonus,
        ], true)) {
            return $this->evaluateState($state, $rootPlayerId, $context)->total();
        }

        $options = array_values(array_filter(
            $this->findOptions($state, $player->playerId, $context),
            fn (GameActionOption $option): bool => ! $this->isAuxiliaryOption($option)
                && $option->type() !== GameActionOptionType::PaidTerraforming,
        ));
        if ($options === []) {
            return $this->evaluateState($state, $rootPlayerId, $context)->total();
        }
        $bestScore = null;
        $maximizing = $player->playerId === $rootPlayerId;
        $context->visitedNodes++;
        $candidates = [];

        foreach ($options as $option) {
            if ($candidates !== [] && $context->isExhausted()) {
                break;
            }

            $simulation = $this->simulate($state, $player->playerId, $option, $context);
            if (in_array($interaction->type, [
                PendingInteractionType::ChooseCompetency,
                PendingInteractionType::SpendSpades,
                PendingInteractionType::BuildWorkshopAfterTerraforming,
                PendingInteractionType::PlacePalaceGuild,
                PendingInteractionType::PlaceNeutralBuilding,
                PendingInteractionType::PlaceBridge,
            ], true)) {
                $breakdown = $this->scoreBreakdown($state, $simulation->state, $rootPlayerId, 0, $context);
                $stateScore = $breakdown->state->total();
                $score = $interaction->type === PendingInteractionType::ChooseCompetency
                    ? $stateScore + $breakdown->boardPosition
                    : $breakdown->total();
            } else {
                $stateScore = $this->evaluateState($simulation->state, $rootPlayerId, $context)->total();
                $score = $stateScore;
            }
            $candidates[] = ['simulation' => $simulation, 'stateScore' => $stateScore, 'score' => $score];
        }

        usort($candidates, static fn (array $left, array $right): int => $maximizing
            ? $right['score'] <=> $left['score']
            : $left['score'] <=> $right['score']);
        $candidates = array_slice($candidates, 0, self::HORIZON_REWARD_BRANCH_LIMIT);

        foreach ($candidates as $candidate) {
            if ($bestScore !== null && $context->isExhausted()) {
                break;
            }
            $simulation = $candidate['simulation'];
            $score = $candidate['score'];
            if ($simulation->state->pendingInteraction !== null) {
                $score += $this->horizonScore($simulation->state, $rootPlayerId, $context) - $candidate['stateScore'];
            }
            $bestScore = $bestScore === null ? $score : ($maximizing ? max($bestScore, $score) : min($bestScore, $score));
        }

        if (! $context->isExhausted()) {
            $context->cachedScores[$cacheKey] = $bestScore;
        }

        return $bestScore;
    }

    private function remainingDepthAfter(
        GameStateData $state,
        GameActionOption $option,
        int $remainingDepth,
    ): int {
        if ($state->pendingInteraction !== null
            || in_array($option->type(), [
                GameActionOptionType::ExchangeResources,
                GameActionOptionType::SacrificePower,
            ], true)) {
            return $remainingDepth;
        }

        return max(0, $remainingDepth - 1);
    }

    private function auxiliaryActionsAfter(GameActionOption $option, int $remaining): int
    {
        return $this->isAuxiliaryOption($option) ? max(0, $remaining - 1) : $remaining;
    }

    private function scoreBreakdown(GameStateData $before, GameStateData $after, int $playerId, int $passPenalty, GameTreeSearchContext $context): GameActionScoreData
    {
        $stateScore = $this->evaluateState($after, $playerId, $context);
        $startedAt = hrtime(true);
        $roundScoring = $this->roundScoringProgressEvaluator->execute($before, $after, $playerId)
            * self::ROUND_SCORING_PRIORITY_WEIGHT;
        $context->timings->roundScoringNanoseconds += hrtime(true) - $startedAt;
        $startedAt = hrtime(true);
        $finalScoring = $before->round->number < 5 ? 0 : $this->finalScoringProgressEvaluator->execute($before, $after, $playerId)
            * self::FINAL_SCORING_PRIORITY_WEIGHT;
        $context->timings->finalScoringNanoseconds += hrtime(true) - $startedAt;
        $startedAt = hrtime(true);
        $boardPosition = $this->boardPositionProgressEvaluator->execute($before, $after, $playerId);
        $context->timings->boardPositionNanoseconds += hrtime(true) - $startedAt;
        $startedAt = hrtime(true);
        $economicNeeds = $this->playerEconomicNeedsEvaluator->execute($before, $after, $playerId);
        $context->timings->economicNeedsNanoseconds += hrtime(true) - $startedAt;

        return new GameActionScoreData(
            state: $stateScore,
            roundScoring: $roundScoring,
            finalScoring: $finalScoring,
            boardPosition: $boardPosition,
            economicNeeds: $economicNeeds,
            passPenalty: $passPenalty,
        );
    }

    /** @return list<GameActionOption> */
    private function findOptions(GameStateData $state, int $playerId, GameTreeSearchContext $context): array
    {
        $startedAt = hrtime(true);
        $options = $this->gameActionOptionFinder->execute($state, $playerId);
        $context->timings->optionFindingNanoseconds += hrtime(true) - $startedAt;
        $context->timings->optionFindingCalls++;

        return $options;
    }

    private function simulate(GameStateData $state, int $playerId, GameActionOption $option, GameTreeSearchContext $context): GameActionSimulationData
    {
        $startedAt = hrtime(true);
        $simulation = $this->gameActionSimulator->execute($state, $playerId, $option);
        $duration = hrtime(true) - $startedAt;
        $copyDuration = $simulation->state->lastDeepCopyNanoseconds();
        $context->timings->simulationNanoseconds += $duration;
        $context->timings->simulationStateCopyNanoseconds += $copyDuration;
        $context->timings->simulationExecutionNanoseconds += $duration - $copyDuration;
        $context->timings->simulationCalls++;
        $actionTimings = $context->timings->simulationsByAction[$option->type()->value] ??= new GameActionSimulationTimingsData($option->type());
        $actionTimings->calls++;
        $actionTimings->nanoseconds += $duration;
        $actionTimings->stateCopyNanoseconds += $copyDuration;
        $actionTimings->executionNanoseconds += $duration - $copyDuration;
        $actionTimings->maximumNanoseconds = max($actionTimings->maximumNanoseconds, $duration);

        return $simulation;
    }

    private function evaluateState(GameStateData $state, int $playerId, GameTreeSearchContext $context): GameStateScoreData
    {
        $startedAt = hrtime(true);
        $score = $this->gameStateEvaluator->evaluateWithBreakdown($state, $playerId);
        $context->timings->stateEvaluationNanoseconds += hrtime(true) - $startedAt;
        $context->timings->stateEvaluationCalls++;

        return $score;
    }

    private function isAuxiliaryOption(GameActionOption $option): bool
    {
        return in_array($option->type(), [
            GameActionOptionType::ExchangeResources,
            GameActionOptionType::SacrificePower,
        ], true);
    }

    /** @param list<GameActionOption> $options */
    private function hasNonPassOption(array $options): bool
    {
        return collect($options)->contains(
            fn (GameActionOption $option): bool => $option->type() !== GameActionOptionType::Pass
                && ! $this->isAuxiliaryOption($option),
        );
    }

    private function passPenalty(
        GameStateData $state,
        int $playerId,
        GameActionOption $option,
        bool $hasNonPassOption,
    ): int {
        return $state->round->number < 6 && $hasNonPassOption && $option->type() === GameActionOptionType::Pass
            ? $this->passValueEvaluator->execute($state, $playerId)
            : 0;
    }

    private function cacheKey(
        GameStateData $state,
        ?int $nextActivePlayerId,
        int $rootPlayerId,
        int $remainingDepth,
        int $auxiliaryActionsRemaining,
    ): string {
        return hash('xxh128', serialize([
            'state' => $this->cacheStateValue($state),
            'nextActivePlayerId' => $nextActivePlayerId,
            'rootPlayerId' => $rootPlayerId,
            'remainingDepth' => $remainingDepth,
            'auxiliaryActionsRemaining' => $auxiliaryActionsRemaining,
        ]));
    }

    private function cacheStateValue(mixed $value): mixed
    {
        if ($value instanceof Data) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            return array_map($this->cacheStateValue(...), $value);
        }

        return $value;
    }

    private function optionCacheKey(GameActionOption $option): string
    {
        if ($option instanceof PowerActionOptionData) {
            return $option->type()->value.':'.$option->action->value;
        }

        return $option->type()->value.':'.serialize($this->cacheStateValue($option));
    }

    private function finalSchoolPassForecast(GameStateData $state, int $playerId, GameTreeSearchContext $context): int
    {
        $player = $this->playerById($state, $playerId);
        if ($state->round->phase !== GamePhase::Actions || $state->round->number !== 6
            || $player?->roundBonus !== RoundBonus::PassSchool
            || in_array($playerId, $state->passedPlayerIds, true)) {
            return $this->evaluateState($state, $playerId, $context)->total();
        }

        $cacheKey = 'final-school-pass:'.$this->cacheKey($state, $playerId, $playerId, 0, 0);
        if (isset($context->cachedScores[$cacheKey])) {
            return $context->cachedScores[$cacheKey];
        }

        $forecast = clone $state;
        $forecast->round = clone $state->round;
        $forecast->round->hasTakenMainAction = false;
        $options = $this->passOptionFinder->execute($forecast, $player);
        $context->visitedNodes++;
        $bestScore = null;
        foreach ($options as $option) {
            if ($bestScore !== null && $context->isExhausted()) {
                break;
            }
            $simulation = $this->simulate($forecast, $playerId, $option, $context);
            $score = $this->scoreBreakdown($state, $simulation->state, $playerId, 0, $context)->total();
            $bestScore = $bestScore === null ? $score : max($bestScore, $score);
        }

        $score = $bestScore ?? $this->evaluateState($state, $playerId, $context)->total();
        if (! $context->isExhausted()) {
            $context->cachedScores[$cacheKey] = $score;
        }

        return $score;
    }

    /**
     * @param array<string, true> $availableOptionKeys
     * @return list<GameActionOption>
     */
    private function newlyFundedOptions(GameStateData $state, int $playerId, array $availableOptionKeys, GameTreeSearchContext $context): array
    {
        if ($context->isExhausted()) {
            return [];
        }

        return array_values(array_filter(
            $this->findOptions($state, $playerId, $context),
            fn (GameActionOption $option): bool => ! $this->isAuxiliaryOption($option)
                && $option->type() !== GameActionOptionType::Pass
                && ! isset($availableOptionKeys[$this->optionCacheKey($option)]),
        ));
    }

    /**
     * @param list<GameActionOption> $options
     * @param array<string, true> $availableOptionKeys
     */
    private function fundedActionForecast(
        GameStateData $state,
        int $playerId,
        array $options,
        array $availableOptionKeys,
        int $auxiliaryActionsRemaining,
        GameTreeSearchContext $context,
    ): ?EvaluatedGameActionData {
        if ($context->isExhausted()) {
            return null;
        }
        if ($options === [] && $context->fundingForecastNodeLimit !== null
            && $context->timings->fundingForecastNodes >= $context->fundingForecastNodeLimit) {
            return null;
        }

        $cacheKey = $this->cacheKey($state, $playerId, $playerId, 0, $auxiliaryActionsRemaining);
        if (array_key_exists($cacheKey, $context->cachedFundingForecasts)) {
            $context->timings->fundingForecastCacheHits++;

            return $context->cachedFundingForecasts[$cacheKey];
        }

        $context->visitedNodes++;
        $context->timings->fundingForecastNodes++;
        $bestScore = null;
        foreach ($options as $option) {
            if ($bestScore !== null && $context->isExhausted()) {
                break;
            }
            $simulation = $this->simulate($state, $playerId, $option, $context);
            $breakdown = $this->scoreBreakdown($state, $simulation->state, $playerId, 0, $context);
            $score = $this->horizonScore($simulation->state, $playerId, $context)
                + $breakdown->total() - $breakdown->state->total();
            if ($bestScore === null || $score > $bestScore->score) {
                $forecastBreakdown = new GameActionScoreData(
                    state: $breakdown->state,
                    roundScoring: $breakdown->roundScoring,
                    finalScoring: $breakdown->finalScoring,
                    boardPosition: $breakdown->boardPosition,
                    economicNeeds: $breakdown->economicNeeds,
                    passPenalty: $breakdown->passPenalty,
                    searchAdjustment: $score - $breakdown->total(),
                );
                $bestScore = new EvaluatedGameActionData($option, $simulation, $score, $forecastBreakdown);
            }
        }
        if ($auxiliaryActionsRemaining > 0 && ! $context->isExhausted()
            && ($context->fundingForecastNodeLimit === null
                || $context->timings->fundingForecastNodes < $context->fundingForecastNodeLimit)) {
            foreach ($this->findOptions($state, $playerId, $context) as $option) {
                if ($context->isExhausted() || ($context->fundingForecastNodeLimit !== null
                    && $context->timings->fundingForecastNodes >= $context->fundingForecastNodeLimit)) {
                    break;
                }
                if (! $this->isAuxiliaryOption($option)) {
                    continue;
                }
                $simulation = $this->simulate($state, $playerId, $option, $context);
                $score = $this->fundedActionForecast(
                    $simulation->state,
                    $playerId,
                    $this->newlyFundedOptions($simulation->state, $playerId, $availableOptionKeys, $context),
                    $availableOptionKeys,
                    $auxiliaryActionsRemaining - 1,
                    $context,
                );
                if ($score !== null) {
                    $auxiliaryBreakdown = $this->scoreBreakdown($state, $simulation->state, $playerId, 0, $context);
                    $progress = $auxiliaryBreakdown->boardPosition + $auxiliaryBreakdown->roundScoring
                        + $auxiliaryBreakdown->finalScoring + min(0, $auxiliaryBreakdown->economicNeeds);
                    $planScore = $score->score + $progress;
                    if ($bestScore === null || $planScore > $bestScore->score) {
                        $breakdown = $score->scoreBreakdown;
                        $planBreakdown = new GameActionScoreData(
                            state: $breakdown->state,
                            roundScoring: $breakdown->roundScoring,
                            finalScoring: $breakdown->finalScoring,
                            boardPosition: $breakdown->boardPosition,
                            economicNeeds: $breakdown->economicNeeds,
                            passPenalty: $breakdown->passPenalty,
                            searchAdjustment: $breakdown->searchAdjustment + $progress,
                        );
                        $bestScore = new EvaluatedGameActionData(
                            $score->option,
                            $score->simulation,
                            $planScore,
                            $planBreakdown,
                            [$option, ...$score->continuation],
                        );
                    }
                }
            }
        }

        if (! $context->isExhausted() && ($auxiliaryActionsRemaining === 0
            || $context->fundingForecastNodeLimit === null
            || $context->timings->fundingForecastNodes < $context->fundingForecastNodeLimit)) {
            $context->cachedFundingForecasts[$cacheKey] = $bestScore;
        }

        return $bestScore;
    }

    private function playerById(GameStateData $state, ?int $playerId): ?GamePlayerStateData
    {
        if ($playerId === null) {
            return null;
        }

        $player = collect($state->players)->firstWhere('playerId', $playerId);

        return $player instanceof GamePlayerStateData ? $player : null;
    }
}
