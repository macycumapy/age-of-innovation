<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\GamePhase;
use Spatie\LaravelData\Data;

final class StartingResourcesResultData extends Data
{
    /**
     * @param list<array{player_id: int, tools: int, coins: int, scholars: int, power: int, books: int, knowledge_steps: int}> $incomeReceipts
     */
    public function __construct(
        public ?int $nextActiveUserId,
        public GamePhase $nextPhase,
        public int $gainedPower,
        public int $victoryPoints,
        public array $incomeReceipts,
    ) {
    }
}
