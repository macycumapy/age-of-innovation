<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\IncomeValueEvaluator;
use App\Domain\GameEngine\Economy\Data\IncomeReceiptData;
use Tests\TestCase;

class IncomeValueEvaluatorTest extends TestCase
{
    public function test_surplus_coin_income_retains_only_its_conversion_value(): void
    {
        $income = $this->income(tools: 1, coins: 8);

        $this->assertSame(90, app(IncomeValueEvaluator::class)->execute($income, 4));
        $this->assertSame(8, $income->coins);
        $this->assertSame(1, $income->tools);
    }

    public function test_tool_income_makes_existing_coin_income_usable_for_building(): void
    {
        $evaluator = app(IncomeValueEvaluator::class);

        $this->assertSame(74, $evaluator->execute($this->income(tools: 2, coins: 8), 4)
            - $evaluator->execute($this->income(tools: 1, coins: 8), 4));
    }

    public function test_balanced_coin_income_keeps_its_working_value(): void
    {
        $this->assertSame(80, app(IncomeValueEvaluator::class)->execute($this->income(tools: 1, coins: 3), 4));
    }

    public function test_existing_gold_covers_construction_before_new_coin_income(): void
    {
        $evaluator = app(IncomeValueEvaluator::class);
        $income = $this->income(tools: 1, coins: 3);

        $this->assertSame(80, $evaluator->execute($income, 4, availableCoins: 25));
        $this->assertSame(80, $evaluator->execute($income, 4, availableCoins: 1));
        $this->assertSame(2, $evaluator->execute($this->income(tools: 1, coins: 4), 4, availableCoins: 25)
            - $evaluator->execute($income, 4, availableCoins: 25));
        $this->assertSame(80, $evaluator->execute($income, 4));
        $this->assertSame(3, $income->coins);
        $this->assertSame(2, $evaluator->execute($this->income(tools: 1, coins: 2), 4, availableCoins: 25)
            - $evaluator->execute($this->income(tools: 1, coins: 1), 4, availableCoins: 25));
        $this->assertSame(20, $evaluator->execute($this->income(tools: 1, coins: 2), 4)
            - $evaluator->execute($this->income(tools: 1, coins: 1), 4));
    }

    public function test_income_without_tools_does_not_forecast_building_capacity(): void
    {
        $this->assertSame(16, app(IncomeValueEvaluator::class)->execute($this->income(tools: 0, coins: 8), 4));
    }

    public function test_income_values_follow_the_receiving_round(): void
    {
        $income = $this->income(tools: 1, coins: 8);
        $evaluator = app(IncomeValueEvaluator::class);

        $this->assertSame(54, $evaluator->execute($income, 5));
        $this->assertSame(18, $evaluator->execute($income, 6));
    }

    private function income(int $tools, int $coins): IncomeReceiptData
    {
        return new IncomeReceiptData(
            playerId: 1,
            tools: $tools,
            coins: $coins,
            scholars: 0,
            power: 0,
            books: 0,
            knowledgeSteps: 0,
            victoryPoints: 0,
        );
    }
}
