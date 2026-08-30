<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum BuildingType: string
{
    /** Мастерская силы 1 для расширения территории и получения дохода. */
    case Workshop = 'workshop';
    /** Гильдия силы 2, улучшаемая до дворца или школы. */
    case Guild = 'guild';
    /** Школа силы 2, при строительстве дающая компетенцию. */
    case School = 'school';
    /** Университет силы 3, дающий компетенцию и снижающий размер города. */
    case University = 'university';
    /** Дворец силы 3 с выбранным уникальным свойством. */
    case Palace = 'palace';
    /** Нейтральная башня силы 2. */
    case Tower = 'tower';
    /** Нейтральный монумент силы 4, позволяющий город из двух зданий. */
    case Monument = 'monument';

    public function powerValue(): int
    {
        return match ($this) {
            self::Workshop => 1,
            self::Guild, self::School, self::Tower => 2,
            self::University, self::Palace => 3,
            self::Monument => 4,
        };
    }

    /** @return list<self> */
    public function upgradeOptions(): array
    {
        return match ($this) {
            self::Workshop => [self::Guild],
            self::Guild => [self::Palace, self::School],
            self::School => [self::University],
            default => [],
        };
    }

    /** @return array{tools: int, coins: int} */
    public function upgradeCostTo(self $target, bool $hasAdjacentOpponent = false): array
    {
        return match ([$this, $target]) {
            [self::Workshop, self::Guild] => ['tools' => 2, 'coins' => $hasAdjacentOpponent ? 3 : 6],
            [self::Guild, self::Palace] => ['tools' => 4, 'coins' => 6],
            [self::Guild, self::School] => ['tools' => 3, 'coins' => 5],
            [self::School, self::University] => ['tools' => 5, 'coins' => 8],
            default => ['tools' => PHP_INT_MAX, 'coins' => PHP_INT_MAX],
        };
    }

    public function supplyLimit(): int
    {
        return match ($this) {
            self::Workshop => 9,
            self::Guild => 4,
            self::School => 3,
            self::University => 2,
            self::Palace => 1,
            self::Tower, self::Monument => 0,
        };
    }
}
