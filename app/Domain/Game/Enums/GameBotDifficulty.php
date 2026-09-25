<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum GameBotDifficulty: string
{
    case Fast = 'fast';
    case Balanced = 'balanced';
    case Strong = 'strong';

    /** @return array{depth: int, branchLimit: int, maxNodes: int} */
    public function searchParameters(): array
    {
        return match ($this) {
            self::Fast => ['depth' => 1, 'branchLimit' => 4, 'maxNodes' => 100],
            self::Balanced => ['depth' => 2, 'branchLimit' => 8, 'maxNodes' => 1000],
            self::Strong => ['depth' => 3, 'branchLimit' => 12, 'maxNodes' => 5000],
        };
    }

    public function title(): string
    {
        return match ($this) {
            self::Fast => 'Слабый',
            self::Balanced => 'Обычный',
            self::Strong => 'Сильный'
        };
    }
}
