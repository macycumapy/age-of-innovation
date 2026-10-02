<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\AutomatedGamePassReason;
use App\Domain\Game\Enums\GameActionSelectionReason;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GamePhase;

final readonly class AutomatedGameDecisionData
{
    /**
     * @param list<GameActionCandidateDiagnosticsData> $candidates
     * @param list<GameActionAvailabilityData> $actionAvailability
     */
    public function __construct(
        public int $gamePlayerId,
        public int $round,
        public GamePhase $phase,
        public ?GameActionType $actionType,
        public ?int $selectedScore,
        public array $candidates,
        public int $visitedNodes,
        public int $durationMilliseconds,
        public bool $budgetExhausted,
        public GameActionSelectionReason $selectionReason,
        public ?AutomatedGamePassReason $passReason,
        public ?AutomatedGameResourcesData $remainingResources = null,
        public array $actionAvailability = [],
    ) {
    }
}
