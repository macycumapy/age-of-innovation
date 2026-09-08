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

    #[DataProvider('playerBoardDisciplineCountsProvider')]
    public function test_player_board_has_the_expected_number_of_slots_for_each_discipline(
        int $playerCount,
        array $expectedDisciplineCounts,
        int $expectedMixedSlotCount,
    ): void {
        $calculator = new InnovationPurchaseCostCalculator();
        $costs = array_map(
            fn (int $slotIndex): array => $calculator->requiredBooks($playerCount, $slotIndex),
            array_keys($calculator->slotColumns($playerCount)),
        );

        foreach ($expectedDisciplineCounts as $discipline => $expectedCount) {
            $this->assertSame(
                $expectedCount,
                count(array_filter($costs, static fn (array $cost): bool => $cost[$discipline] === 2)),
            );
        }

        $this->assertSame(
            $expectedMixedSlotCount,
            count(array_filter($costs, static fn (array $cost): bool => array_sum($cost) === 4)),
        );
    }

    /** @return iterable<string, array{int, array<string, int>, int}> */
    public static function playerBoardDisciplineCountsProvider(): iterable
    {
        yield '2 игрока' => [
            2,
            ['banking' => 2, 'law' => 2, 'engineering' => 2, 'medicine' => 2],
            2,
        ];
        yield '3 игрока' => [
            3,
            ['banking' => 2, 'law' => 2, 'engineering' => 2, 'medicine' => 2],
            0,
        ];
        yield '4 игрока' => [
            4,
            ['banking' => 3, 'law' => 3, 'engineering' => 3, 'medicine' => 3],
            2,
        ];
        yield '5 игроков' => [
            5,
            ['banking' => 3, 'law' => 3, 'engineering' => 3, 'medicine' => 3],
            0,
        ];
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
