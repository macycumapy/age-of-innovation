<?php

declare(strict_types=1);

namespace App\Domain\Automation\Evaluation\Services;

use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;

final class IncomeValueEvaluator
{
    /** Even an undiscounted guild costs at most three coins per tool. */
    private const int BUILDING_COINS_PER_TOOL = 3;

    public function execute(IncomeReceiptData $income, int $receivingRound): int
    {
        $workingCoins = min($income->coins, $income->tools * self::BUILDING_COINS_PER_TOOL);

        return $income->victoryPoints * 10
            + $workingCoins * $this->resourceWeight(20, 2, $receivingRound)
            + ($income->coins - $workingCoins) * 2
            + $income->tools * $this->resourceWeight(20, 2, $receivingRound)
            + $income->scholars * $this->resourceWeight(40, 2, $receivingRound)
            + $income->books * $this->resourceWeight(25, 2, $receivingRound)
            + $income->power * $this->resourceWeight(7, 1, $receivingRound)
            + $income->knowledgeSteps * $this->resourceWeight(10, 0, $receivingRound);
    }

    private function resourceWeight(int $earlyWeight, int $finalWeight, int $receivingRound): int
    {
        $remainingRounds = max(0, min(2, 6 - $receivingRound));

        return intdiv($earlyWeight * $remainingRounds + $finalWeight * (2 - $remainingRounds), 2);
    }
}
