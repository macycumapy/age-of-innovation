<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum GameBotDifficulty: string
{
    case Fast = 'fast';
    case Balanced = 'balanced';
    case Strong = 'strong';

    /** @return array{depth: int, branchLimit: int, maxNodes: int, maxTimeMilliseconds: int} */
    public function searchParameters(): array
    {
        return match ($this) {
            self::Fast => ['depth' => 1, 'branchLimit' => 4, 'maxNodes' => 50, 'maxTimeMilliseconds' => 2_000],
            self::Balanced => ['depth' => 2, 'branchLimit' => 4, 'maxNodes' => 300, 'maxTimeMilliseconds' => 8_000],
            self::Strong => ['depth' => 3, 'branchLimit' => 6, 'maxNodes' => 750, 'maxTimeMilliseconds' => 30_000],
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
