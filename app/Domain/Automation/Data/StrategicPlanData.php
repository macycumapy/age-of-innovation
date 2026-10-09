<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

use App\Domain\Automation\Enums\StrategicApproach;
use App\Domain\Automation\Enums\StrategicObjective;
use App\Domain\GameEngine\Economy\Data\BookPaymentData;
use Spatie\LaravelData\Data;

final class StrategicPlanData extends Data
{
    /**
     * @param list<string> $anchorHexIds
     * @param list<string> $expansionHexIds
     */
    public function __construct(
        public StrategicObjective $objective,
        public array $anchorHexIds,
        public array $expansionHexIds,
        public int $reservedTools,
        public int $reservedCoins,
        public int $requiredAnnexes,
        public int $score,
        public int $remainingActions = 0,
        public ?string $targetId = null,
        public int $reservedBooks = 0,
        public int $reservedScholars = 0,
        public ?StrategicApproach $approach = null,
        public ?string $secondaryTargetId = null,
        public ?int $targetLevel = null,
        public int $plannedRound = 0,
        public ?BookPaymentData $requiredBooks = null,
    ) {
    }
}
