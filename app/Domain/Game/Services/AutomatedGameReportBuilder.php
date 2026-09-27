<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Data\AutomatedGameDecisionData;
use App\Domain\Game\Data\AutomatedGameSimulationResultData;

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
                    'candidates' => $decision->candidates,
                    'visited_nodes' => $decision->visitedNodes,
                    'duration_milliseconds' => $decision->durationMilliseconds,
                    'budget_exhausted' => $decision->budgetExhausted,
                    'pass_reason' => $decision->passReason,
                    'remaining_resources' => $decision->remainingResources === null ? null : [
                        'coins' => $decision->remainingResources->coins,
                        'tools' => $decision->remainingResources->tools,
                        'scholars' => $decision->remainingResources->scholars,
                        'books' => $decision->remainingResources->books,
                        'power' => $decision->remainingResources->power,
                        'spades' => $decision->remainingResources->spades,
                    ],
                ],
                $result->decisions,
            ),
        ];
    }
}
