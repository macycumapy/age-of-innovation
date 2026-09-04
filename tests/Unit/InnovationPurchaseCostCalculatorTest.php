<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Services\InnovationPurchaseCostCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class InnovationPurchaseCostCalculatorTest extends TestCase
{
    #[DataProvider('inventionSequenceProvider')]
    public function test_book_cost_increases_with_each_owned_innovation(
        int $ownedInventionCount,
        bool $redPlayerBoard,
        int $expectedAnyBooks,
        int $expectedTotalBooks,
    ): void {
        $cost = (new InnovationPurchaseCostCalculator())->cost(
            playerCount: 2,
            slotIndex: 0,
            ownedInventionCount: $ownedInventionCount,
            skipsSecondInventionSurcharge: $redPlayerBoard,
            hasPalace: true,
        );

        $this->assertSame($expectedAnyBooks, $cost['extraAnyBooks']);
        $this->assertSame($expectedTotalBooks, $cost['totalBooks']);
    }

    /** @return array<string, array{int, bool, int, int}> */
    public static function inventionSequenceProvider(): array
    {
        return [
            'first invention' => [0, false, 1, 5],
            'second invention' => [1, false, 2, 6],
            'third invention' => [2, false, 3, 7],
            'red board first invention' => [0, true, 1, 5],
            'red board second invention' => [1, true, 1, 5],
            'red board third invention' => [2, true, 3, 7],
        ];
    }

    public function test_innovation_column_determines_required_book_colours(): void
    {
        $calculator = new InnovationPurchaseCostCalculator();

        $this->assertSame(
            ['banking' => 2, 'law' => 2, 'engineering' => 0, 'medicine' => 0],
            $calculator->requiredBooks(playerCount: 4, slotIndex: 0),
        );
        $this->assertSame(
            ['banking' => 0, 'law' => 0, 'engineering' => 2, 'medicine' => 2],
            $calculator->requiredBooks(playerCount: 4, slotIndex: 1),
        );
        $this->assertSame(
            ['banking' => 2, 'law' => 0, 'engineering' => 0, 'medicine' => 0],
            $calculator->requiredBooks(playerCount: 4, slotIndex: 2),
        );
        $this->assertSame(
            ['banking' => 0, 'law' => 2, 'engineering' => 0, 'medicine' => 0],
            $calculator->requiredBooks(playerCount: 4, slotIndex: 3),
        );
        $this->assertSame(
            ['banking' => 0, 'law' => 0, 'engineering' => 2, 'medicine' => 0],
            $calculator->requiredBooks(playerCount: 4, slotIndex: 4),
        );
        $this->assertSame(
            ['banking' => 0, 'law' => 0, 'engineering' => 0, 'medicine' => 2],
            $calculator->requiredBooks(playerCount: 4, slotIndex: 5),
        );
    }

    public function test_player_without_a_palace_pays_five_coins(): void
    {
        $calculator = new InnovationPurchaseCostCalculator();

        $this->assertSame(5, $calculator->cost(2, 0, 0, false, false)['coins']);
        $this->assertSame(0, $calculator->cost(2, 0, 0, false, true)['coins']);
    }

    public function test_upper_and_regular_slots_have_different_any_book_requirements(): void
    {
        $calculator = new InnovationPurchaseCostCalculator();

        $this->assertSame(1, $calculator->cost(4, 0, 0, false, true)['extraAnyBooks']);
        $this->assertSame(3, $calculator->cost(4, 2, 0, false, true)['extraAnyBooks']);
        $this->assertSame(4, $calculator->cost(4, 2, 1, false, true)['extraAnyBooks']);
        $this->assertSame(5, $calculator->cost(4, 2, 2, false, true)['extraAnyBooks']);
    }
}
