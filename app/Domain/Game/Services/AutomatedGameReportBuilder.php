<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\AutomatedGameDecisionData;
use App\Domain\Game\Data\AutomatedGameSimulationResultData;
use App\Domain\Game\Data\GameActionAvailabilityData;
use App\Domain\Game\Data\GameActionCandidateDiagnosticsData;
use App\Domain\Game\Enums\GameActionAvailabilityReason;

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
