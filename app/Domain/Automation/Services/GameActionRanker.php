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
use App\Domain\GameEngine\Board\Services\SpendSpadesOptionFinder;
use App\Domain\GameEngine\Board\Services\WorkshopAfterTerraformingOptionFinder;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Services\ChoosePalaceOptionFinder;
use App\Domain\GameEngine\Research\Services\ChooseCompetencyOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Towns\Services\ChooseTownOptionFinder;
use App\Domain\GameEngine\Turns\Actions\ApplyFinishActionTurnAction;
use InvalidArgumentException;

final class GameActionRanker
{
    private int $lastVisitedNodes = 0;

    private bool $lastBudgetExhausted = false;

    private ?GameActionSearchTimingsData $lastSearchTimings = null;

    private const int MAX_AUXILIARY_ACTIONS_PER_TURN = 1;

    private const int ROUND_SCORING_PRIORITY_WEIGHT = 10;

    private const int FINAL_SCORING_PRIORITY_WEIGHT = 10;

    public function __construct(
        private GameActionOptionFinder $gameActionOptionFinder,
        private GameActionSimulator $gameActionSimulator,
        private GameStateEvaluator $gameStateEvaluator,
        private RoundScoringProgressEvaluator $roundScoringProgressEvaluator,
        private FinalScoringProgressEvaluator $finalScoringProgressEvaluator,
        private BoardPositionProgressEvaluator $boardPositionProgressEvaluator,
        private PassValueEvaluator $passValueEvaluator,
        private PlayerEconomicNeedsEvaluator $playerEconomicNeedsEvaluator,
        private WorkshopAfterTerraformingOptionFinder $workshopAfterTerraformingOptionFinder,
        private SpendSpadesOptionFinder $spendSpadesOptionFinder,
        private ChooseCompetencyOptionFinder $chooseCompetencyOptionFinder,
        private ChoosePalaceOptionFinder $choosePalaceOptionFinder,
        private ChooseTownOptionFinder $chooseTownOptionFinder,
        private ApplyFinishActionTurnAction $applyFinishActionTurn,
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
        $candidates = [];

        $options = $this->findOptions($state, $playerId, $context);
        if ($auxiliaryActionsRemaining === 0) {
            $options = array_values(array_filter(
                $options,
                fn (GameActionOption $option): bool => ! $this->isAuxiliaryOption($option),
            ));
        }
        $hasNonPassOption = $this->hasNonPassOption($options);

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

        for ($searchDepth = 1; $searchDepth <= $depth && ! $context->isExhausted(); $searchDepth++) {
            $iterationActions = [];
            foreach ($candidates as $candidate) {
                if ($context->isExhausted()) {
                    break;
                }
                $option = $candidate['option'];
                $simulation = $candidate['simulation'];
                $initialBreakdown = $candidate['scoreBreakdown'];
                $searchStartedAt = hrtime(true);
                $searchScore = $this->search(
                    new GameActionSimulationData($simulation->state->deepCopy(), $simulation->nextActivePlayerId),
                    $playerId,
                    $this->remainingDepthAfter($state, $option, $searchDepth),
                    $this->auxiliaryActionsAfter($option, $auxiliaryActionsRemaining),
                    $branchLimit,
                    $context,
                    PHP_INT_MIN,
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
                    searchAdjustment: $searchScore - $initialBreakdown->state->total(),
                );
                $iterationActions[] = [
                    'evaluation' => new EvaluatedGameActionData(
                        $option,
                        $simulation,
                        $scoreBreakdown->total(),
                        $scoreBreakdown,
                    ),
                    'index' => $candidate['index'],
                    'isAuxiliary' => $this->isAuxiliaryOption($option),
                    'isPass' => $option->type() === GameActionOptionType::Pass,
                ];
            }

            if ($context->isExhausted() || count($iterationActions) !== count($candidates)) {
                break;
            }

            $rankedActions = $iterationActions;
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

    public function lastBudgetExhausted(): bool
    {
        return $this->lastBudgetExhausted;
    }

    public function lastSearchTimings(): GameActionSearchTimingsData
    {
        return $this->lastSearchTimings ?? new GameActionSearchTimingsData();
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
            $simulations[] = [
                'simulation' => $nextSimulation,
                'score' => $this->evaluateState($nextSimulation->state, $rootPlayerId, $context)->total()
                    + ($maximizing ? -$passPenalty : $passPenalty),
                'remainingDepth' => $this->remainingDepthAfter($state, $option, $remainingDepth),
                'auxiliaryActionsRemaining' => $this->auxiliaryActionsAfter($option, $auxiliaryActionsRemaining),
                'passPenalty' => $passPenalty,
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
        $simulations = array_slice($simulations, 0, $branchLimit);
        $bestScore = $maximizing ? PHP_INT_MIN : PHP_INT_MAX;
        $wasCutOff = false;

        foreach ($simulations as $candidate) {
            $score = $this->search(
                $candidate['simulation'],
                $rootPlayerId,
                $candidate['remainingDepth'],
                $candidate['auxiliaryActionsRemaining'],
                $branchLimit,
                $context,
                $alpha,
                $beta,
            ) + ($maximizing ? -$candidate['passPenalty'] : $candidate['passPenalty']);

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

        if (! $wasCutOff) {
            $context->cachedScores[$cacheKey] = $bestScore;
        }

        return $bestScore;
    }

    private function horizonScore(GameStateData $state, int $rootPlayerId, GameTreeSearchContext $context): int
    {
        $interaction = $state->pendingInteraction;
        if ($interaction === null || $context->isExhausted()) {
            return $this->evaluateState($state, $rootPlayerId, $context)->total();
        }

        $player = $this->playerById($state, $interaction->playerId);
        if ($player === null) {
            return $this->evaluateState($state, $rootPlayerId, $context)->total();
        }

        $isBuildingReward = ($interaction->context['reason'] ?? null) === 'building';
        $optionsStartedAt = hrtime(true);
        $options = match ($interaction->type) {
            PendingInteractionType::SpendSpades => $this->spendSpadesOptionFinder->execute($state, $player),
            PendingInteractionType::BuildWorkshopAfterTerraforming => $this->workshopAfterTerraformingOptionFinder->execute($state, $player),
            PendingInteractionType::ChooseCompetency => $isBuildingReward ? $this->chooseCompetencyOptionFinder->execute($state, $player) : [],
            PendingInteractionType::ChoosePalace => $isBuildingReward ? $this->choosePalaceOptionFinder->execute($state, $player) : [],
            PendingInteractionType::ChooseTown => $this->chooseTownOptionFinder->execute($state, $player->playerId),
            default => [],
        };
        $context->timings->optionFindingNanoseconds += hrtime(true) - $optionsStartedAt;
        $context->timings->optionFindingCalls++;
        if ($options === []) {
            return $this->evaluateState($state, $rootPlayerId, $context)->total();
        }
        $bestScore = null;
        $maximizing = $player->playerId === $rootPlayerId;
        $context->visitedNodes++;

        foreach ($options as $option) {
            if ($bestScore !== null && $context->isExhausted()) {
                break;
            }

            $simulation = $this->simulate($state, $player->playerId, $option, $context);
            if (in_array($interaction->type, [PendingInteractionType::SpendSpades, PendingInteractionType::BuildWorkshopAfterTerraforming], true)) {
                $breakdown = $this->scoreBreakdown($state, $simulation->state, $rootPlayerId, 0, $context);
                $stateScore = $breakdown->state->total();
                $score = $breakdown->total();
            } else {
                $stateScore = $this->evaluateState($simulation->state, $rootPlayerId, $context)->total();
                $score = $stateScore;
            }
            if (in_array($simulation->state->pendingInteraction?->type, [
                PendingInteractionType::SpendSpades,
                PendingInteractionType::BuildWorkshopAfterTerraforming,
                PendingInteractionType::ChooseTown,
            ], true)) {
                $score += $this->horizonScore($simulation->state, $rootPlayerId, $context) - $stateScore;
            }
            $bestScore = $bestScore === null ? $score : ($maximizing ? max($bestScore, $score) : min($bestScore, $score));
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
        $finalScoring = $this->finalScoringProgressEvaluator->execute($before, $after, $playerId)
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
        return $hasNonPassOption && $option->type() === GameActionOptionType::Pass
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
            'state' => $state,
            'nextActivePlayerId' => $nextActivePlayerId,
            'rootPlayerId' => $rootPlayerId,
            'remainingDepth' => $remainingDepth,
            'auxiliaryActionsRemaining' => $auxiliaryActionsRemaining,
        ]));
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
