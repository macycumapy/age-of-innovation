<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\GamePhase;
use Spatie\LaravelData\Data;

final class StartingBuildingResultData extends Data
{
    /** @param list<array{player_id: int, tools: int, coins: int, scholars: int, power: int, books: int, knowledge_steps: int}> $incomeReceipts */
    public function __construct(
        public string $hexId,
        public BuildingType $buildingType,
        public int $nextActivePlayerId,
        public GamePhase $nextPhase,
        public array $incomeReceipts,
    ) {
    }
}
