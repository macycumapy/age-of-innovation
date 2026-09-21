<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\RoundBonus;
use Spatie\LaravelData\Data;

final class ChooseRoundBonusResultData extends Data
{
    /**
     * @param array<int, mixed> $incomeReceipts
     * @param array<int, mixed> $finalScoring
     * @param array<int, mixed> $scienceBonusReceipts
     */
    public function __construct(
        public RoundBonus $oldRoundBonus,
        public RoundBonus $roundBonus,
        public int $bonusCoins,
        public ?int $nextActiveUserId,
        public GamePhase $phase,
        public bool $nextRoundStarted,
        public array $incomeReceipts,
        public array $finalScoring,
        public array $scienceBonusReceipts,
    ) {
    }
}
