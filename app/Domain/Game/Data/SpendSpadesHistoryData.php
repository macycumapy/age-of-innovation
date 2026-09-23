<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\GamePhase;
use Spatie\LaravelData\Data;

final class SpendSpadesHistoryData extends Data
{
    /**
     * @param list<string> $buildableHexIds
     * @param list<array<string, mixed>> $incomeReceipts
     * @param list<array<string, mixed>> $scienceBonusReceipts
     * @param list<array<string, mixed>> $finalScoring
     */
    public function __construct(
        public string $hexId,
        public ?string $terrainBefore,
        public ?string $terrainAfter,
        public int $remainingSpades,
        public ?string $targetTerrain,
        public GamePhase $phase,
        public bool $incomeStarted,
        public int $round,
        public array $buildableHexIds,
        public bool $buildOffered,
        public int $paidTools,
        public int $paidSpadeCount,
        public int $spentSpades,
        public bool $resumeStartingBuildingPlacement,
        public bool $chooseStartingCompetencyAfterSpade,
        public int $bonusCoins,
        public int $tunnelTools,
        public int $tunnelVictoryPoints,
        public int $flightScholarCost,
        public int $flightVictoryPoints,
        public int $victoryPoints,
        public bool $felineBonusPending,
        public bool $lizardBonusPending,
        public bool $lizardFreeWorkshop,
        public array $incomeReceipts = [],
        public array $scienceBonusReceipts = [],
        public array $finalScoring = [],
    ) {
    }
}
