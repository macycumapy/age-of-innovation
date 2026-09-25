<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\GamePhase;
use Spatie\LaravelData\Data;

final class StartingSetupResolutionData extends Data
{
    /** @param list<IncomeReceiptData> $incomeReceipts */
    public function __construct(
        public int $nextActivePlayerId,
        public GamePhase $phase,
        public array $incomeReceipts,
    ) {
    }
}
