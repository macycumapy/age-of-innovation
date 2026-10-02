<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Spatie\LaravelData\Data;

final class ChooseCompetencyResultData extends Data
{
    /** @param list<IncomeReceiptData> $incomeReceipts */
    public function __construct(
        public int $nextActivePlayerId,
        public GamePhase $nextPhase,
        public string $reason,
        public string $builtHexId,
        public int $gainedPower,
        public int $victoryPoints,
        public array $incomeReceipts = [],
    ) {
    }
}
