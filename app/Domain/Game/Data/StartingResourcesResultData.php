<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\GamePhase;
use Spatie\LaravelData\Data;

final class StartingResourcesResultData extends Data
{
    /**
     * @param list<IncomeReceiptData> $incomeReceipts
     */
    public function __construct(
        public ?int $nextActivePlayerId,
        public GamePhase $nextPhase,
        public int $gainedPower,
        public int $victoryPoints,
        public array $incomeReceipts,
    ) {
    }
}
