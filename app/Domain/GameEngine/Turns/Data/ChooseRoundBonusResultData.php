<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Turns\Data;

use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Spatie\LaravelData\Data;

final class ChooseRoundBonusResultData extends Data
{
    /**
     * @param list<IncomeReceiptData> $incomeReceipts
     * @param array<int, mixed> $finalScoring
     * @param array<int, mixed> $scienceBonusReceipts
     */
    public function __construct(
        public RoundBonus $oldRoundBonus,
        public RoundBonus $roundBonus,
        public int $bonusCoins,
        public ?int $nextActivePlayerId,
        public GamePhase $phase,
        public bool $nextRoundStarted,
        public array $incomeReceipts,
        public array $finalScoring,
        public array $scienceBonusReceipts,
    ) {
    }
}
