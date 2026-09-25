<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\GamePhase;
use Spatie\LaravelData\Data;

final class ScienceBonusBookDistributionResultData extends Data
{
    /**
     * @param list<IncomeReceiptData> $incomeReceipts
     * @param list<array{playerId: int, victoryPoints: int, sources: list<array{source: string, id: string, value: int, rank: int, points: int}>}> $finalScoring
     * @param list<array<string, int|string>> $scienceBonusReceipts
     */
    public function __construct(
        public ?int $nextActivePlayerId,
        public GamePhase $nextPhase,
        public array $incomeReceipts,
        public array $finalScoring,
        public array $scienceBonusReceipts,
    ) {
    }
}
