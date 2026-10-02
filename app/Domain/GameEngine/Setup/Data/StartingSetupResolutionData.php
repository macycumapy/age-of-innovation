<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Data;

use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
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
