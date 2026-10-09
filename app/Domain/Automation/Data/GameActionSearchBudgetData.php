<?php

declare(strict_types=1);

namespace App\Domain\Automation\Data;

use InvalidArgumentException;

final readonly class GameActionSearchBudgetData
{
    public function __construct(
        public int $maxNodes,
        public int $maxTimeMilliseconds,
    ) {
        if ($maxNodes < 1 || $maxTimeMilliseconds < 1) {
            throw new InvalidArgumentException('Бюджет поиска должен быть положительным.');
        }
    }
}
