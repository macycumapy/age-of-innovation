<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Attributes\MapName;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\Mappers\SnakeCaseMapper;

#[MapName(SnakeCaseMapper::class)]
final class IncomeReceiptData extends Data
{
    public function __construct(
        public int $playerId,
        public int $tools,
        public int $coins,
        public int $scholars,
        public int $power,
        public int $books,
        public int $knowledgeSteps,
        public int $victoryPoints,
    ) {
    }

    /**
     * @return array{tools: int, coins: int, scholars: int, power: int, books: int, knowledgeSteps: int, victoryPoints: int}
     */
    public function resourceAmounts(): array
    {
        return [
            'tools' => $this->tools,
            'coins' => $this->coins,
            'scholars' => $this->scholars,
            'power' => $this->power,
            'books' => $this->books,
            'knowledgeSteps' => $this->knowledgeSteps,
            'victoryPoints' => $this->victoryPoints,
        ];
    }
}
