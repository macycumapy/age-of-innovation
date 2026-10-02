<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Setup\Data;

use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Spatie\LaravelData\Data;

final class StartingBuildingResultData extends Data
{
    /** @param list<IncomeReceiptData> $incomeReceipts */
    public function __construct(
        public string $hexId,
        public BuildingType $buildingType,
        public int $nextActivePlayerId,
        public GamePhase $nextPhase,
        public array $incomeReceipts,
    ) {
    }
}
