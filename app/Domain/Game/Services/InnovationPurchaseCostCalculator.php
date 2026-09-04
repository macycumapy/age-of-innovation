<?php

declare(strict_types=1);

namespace App\Domain\Game\Services;

use App\Domain\Game\Enums\Innovation;
use InvalidArgumentException;

final class InnovationPurchaseCostCalculator
{
    public const int BASE_BOOK_COST = 5;

    public const int ANY_BOOKS = 1;

    public const int PALACE_COIN_SURCHARGE = 5;

    public const int MAX_INVENTIONS = 3;

    /**
     * @return array{
     *     requiredBooks: array{banking: int, law: int, engineering: int, medicine: int},
     *     extraAnyBooks: int,
     *     totalBooks: int,
     *     coins: int
     * }
     */
    public function cost(
        int $playerCount,
        int $slotIndex,
        int $ownedInventionCount,
        bool $skipsSecondInventionSurcharge,
        bool $hasPalace,
    ): array {
        $requiredBooks = $this->requiredBooks($playerCount, $slotIndex);
        $totalBooks = self::BASE_BOOK_COST
            + $this->extraAnyBooks($ownedInventionCount, $skipsSecondInventionSurcharge);

        return [
            'requiredBooks' => $requiredBooks,
            'extraAnyBooks' => $totalBooks - array_sum($requiredBooks),
            'totalBooks' => $totalBooks,
            'coins' => $hasPalace ? 0 : self::PALACE_COIN_SURCHARGE,
        ];
    }

    public function extraAnyBooks(int $ownedInventionCount, bool $skipsSecondInventionSurcharge): int
    {
        return match (max(0, $ownedInventionCount)) {
            0 => 0,
            1 => $skipsSecondInventionSurcharge ? 0 : 1,
            default => 2,
        };
    }

    /**
     * @return array{banking: int, law: int, engineering: int, medicine: int}
     */
    public function requiredBooks(int $playerCount, int $slotIndex): array
    {
        $column = $this->columnIndex($playerCount, $slotIndex);

        if ($slotIndex < $this->upperSlotCount($playerCount)) {
            return $column <= 1
                ? ['banking' => 2, 'law' => 2, 'engineering' => 0, 'medicine' => 0]
                : ['banking' => 0, 'law' => 0, 'engineering' => 2, 'medicine' => 2];
        }

        $requiredBooks = ['banking' => 0, 'law' => 0, 'engineering' => 0, 'medicine' => 0];
        $discipline = ['banking', 'law', 'engineering', 'medicine'][$column];
        $requiredBooks[$discipline] = 2;

        return $requiredBooks;
    }

    public function columnIndex(int $playerCount, int $slotIndex): int
    {
        $column = $this->slotColumns($playerCount)[$slotIndex] ?? null;

        if ($column === null) {
            throw new InvalidArgumentException('Неизвестная ячейка инновации.');
        }

        return $column;
    }

    /**
     * @return list<int>
     */
    public function slotColumns(int $playerCount): array
    {
        $upper = $playerCount % 2 === 0 ? [0, 2] : [0, 1, 2, 3];
        $row = [0, 1, 2, 3];

        return [
            ...$upper,
            ...$row,
            ...($playerCount >= 4 ? $row : []),
        ];
    }

    private function upperSlotCount(int $playerCount): int
    {
        return $playerCount % 2 === 0 ? 2 : 4;
    }

    /**
     * @param list<Innovation|string> $innovations
     */
    public function slotIndex(array $innovations, Innovation $innovation): int
    {
        foreach ($innovations as $index => $candidate) {
            $value = $candidate instanceof Innovation ? $candidate->value : $candidate;

            if ($value === $innovation->value) {
                return $index;
            }
        }

        throw new InvalidArgumentException('Эта инновация отсутствует на планшете.');
    }
}
