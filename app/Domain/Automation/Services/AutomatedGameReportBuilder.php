<?php

declare(strict_types=1);

namespace App\Domain\Automation\Services;

use App\Domain\Automation\Data\AutomatedGameDecisionData;
use App\Domain\Automation\Data\AutomatedGameSimulationResultData;
use App\Domain\Automation\Data\GameActionCandidateDiagnosticsData;
use App\Domain\Automation\Data\GameActionSimulationTimingsData;
use App\Domain\GameEngine\Interactions\Data\GameActionAvailabilityData;
use App\Domain\GameEngine\Interactions\Enums\GameActionAvailabilityReason;

final class AutomatedGameReportBuilder
{
    /** @return array<string, mixed> */
    public function execute(AutomatedGameSimulationResultData $result): array
    {
        return [
            'completed' => $result->completed,
            'stopped_reason' => $result->stoppedReason,
            'duration_milliseconds' => $result->durationMilliseconds,
            'final_scores' => $result->finalScores,
            'decisions' => array_map(
                static fn (AutomatedGameDecisionData $decision): array => [
                    'game_player_id' => $decision->gamePlayerId,
                    'round' => $decision->round,
                    'phase' => $decision->phase->value,
                    'action_type' => $decision->actionType?->value,
                    'selected_score' => $decision->selectedScore,
                    'candidates' => array_map(
                        static fn (GameActionCandidateDiagnosticsData $candidate): array => [
                            'type' => $candidate->type->value,
                            'parameters' => $candidate->option->toArray(),
                            'score' => $candidate->score,
                            'rank' => $candidate->rank,
                            'score_delta' => $candidate->scoreDelta,
                            'selected' => $candidate->selected,
                            'score_breakdown' => [
                                'victory_points' => $candidate->scoreBreakdown->state->victoryPoints,
                                'resources' => $candidate->scoreBreakdown->state->resources,
                                'development' => $candidate->scoreBreakdown->state->development,
                                'buildings' => $candidate->scoreBreakdown->state->buildings,
                                'future_income' => $candidate->scoreBreakdown->state->futureIncome,
                                'opponent' => -$candidate->scoreBreakdown->state->opponent,
                                'round_scoring' => $candidate->scoreBreakdown->roundScoring,
                                'final_scoring' => $candidate->scoreBreakdown->finalScoring,
                                'board_position' => $candidate->scoreBreakdown->boardPosition,
                                'economic_needs' => $candidate->scoreBreakdown->economicNeeds,
                                'pass_penalty' => -$candidate->scoreBreakdown->passPenalty,
                                'search_adjustment' => $candidate->scoreBreakdown->searchAdjustment,
                            ],
                        ],
                        $decision->candidates,
                    ),
                    'visited_nodes' => $decision->visitedNodes,
                    'duration_milliseconds' => $decision->durationMilliseconds,
                    'budget_exhausted' => $decision->budgetExhausted,
                    'search_timings' => $decision->searchTimings === null ? null : [
                        'option_finding_ms' => $decision->searchTimings->optionFindingNanoseconds / 1_000_000,
                        'simulation_ms' => $decision->searchTimings->simulationNanoseconds / 1_000_000,
                        'simulation_state_copy_ms' => $decision->searchTimings->simulationStateCopyNanoseconds / 1_000_000,
                        'simulation_execution_ms' => $decision->searchTimings->simulationExecutionNanoseconds / 1_000_000,
                        'state_evaluation_ms' => $decision->searchTimings->stateEvaluationNanoseconds / 1_000_000,
                        'round_scoring_ms' => $decision->searchTimings->roundScoringNanoseconds / 1_000_000,
                        'final_scoring_ms' => $decision->searchTimings->finalScoringNanoseconds / 1_000_000,
                        'board_position_ms' => $decision->searchTimings->boardPositionNanoseconds / 1_000_000,
                        'economic_needs_ms' => $decision->searchTimings->economicNeedsNanoseconds / 1_000_000,
                        'continuation_search_inclusive_ms' => $decision->searchTimings->continuationSearchNanoseconds / 1_000_000,
                        'option_finding_calls' => $decision->searchTimings->optionFindingCalls,
                        'simulation_calls' => $decision->searchTimings->simulationCalls,
                        'simulations_by_action' => array_map(
                            static fn (GameActionSimulationTimingsData $timings): array => [
                                'type' => $timings->type->value,
                                'calls' => $timings->calls,
                                'total_ms' => $timings->nanoseconds / 1_000_000,
                                'state_copy_ms' => $timings->stateCopyNanoseconds / 1_000_000,
                                'execution_ms' => $timings->executionNanoseconds / 1_000_000,
                                'average_ms' => $timings->nanoseconds / $timings->calls / 1_000_000,
                                'maximum_ms' => $timings->maximumNanoseconds / 1_000_000,
                            ],
                            array_values($decision->searchTimings->simulationsByAction),
                        ),
                        'state_evaluation_calls' => $decision->searchTimings->stateEvaluationCalls,
                    ],
                    'selection_reason' => $decision->selectionReason->value,
                    'pass_reason' => $decision->passReason?->value,
                    'remaining_resources' => $decision->remainingResources === null ? null : [
                        'coins' => $decision->remainingResources->coins,
                        'tools' => $decision->remainingResources->tools,
                        'scholars' => $decision->remainingResources->scholars,
                        'books' => $decision->remainingResources->books,
                        'power' => $decision->remainingResources->power,
                        'spades' => $decision->remainingResources->spades,
                    ],
                    'action_availability' => array_map(
                        static fn (GameActionAvailabilityData $availability): array => [
                            'type' => $availability->type->value,
                            'available_option_count' => $availability->availableOptionCount,
                            'unavailable_reasons' => array_map(
                                static fn (GameActionAvailabilityReason $reason): string => $reason->value,
                                $availability->unavailableReasons,
                            ),
                        ],
                        $decision->actionAvailability,
                    ),
                ],
                $result->decisions,
            ),
        ];
    }
}
