<?php

declare(strict_types=1);

namespace App\Domain\Game\Enums;

enum TerrainType: string
{
    /** Пустыня: стартовая лопата после размещения начальных зданий. */
    case Desert = 'desert';
    /** Равнина: удешевлённое продвижение преобразования. */
    case Plains = 'plains';
    /** Болото: дополнительный учёный и стартовая сила 9/3. */
    case Swamp = 'swamp';
    /** Озеро: стартовый уровень судоходства 1. */
    case Lake = 'lake';
    /** Лес: по стартовому шагу во всех дисциплинах и сила 8/4. */
    case Forest = 'forest';
    /** Горы: дополнительный доход монет. */
    case Mountain = 'mountain';
    /** Пустошь: дополнительная книга, инструмент и льгота второго изобретения. */
    case Wasteland = 'wasteland';
    /** Водная ячейка, недоступная для строительства и преобразования. */
    case Water = 'water';

    public function isHomeland(): bool
    {
        return $this !== self::Water;
    }

    public function stepTowards(self $target): self
    {
        if ($this === self::Water || $target === self::Water || $this === $target) {
            return $this;
        }

        $terrainCycle = [
            self::Desert,
            self::Plains,
            self::Swamp,
            self::Lake,
            self::Forest,
            self::Mountain,
            self::Wasteland,
        ];
        $currentIndex = array_search($this, $terrainCycle, true);
        $targetIndex = array_search($target, $terrainCycle, true);

        if (! is_int($currentIndex) || ! is_int($targetIndex)) {
            return $this;
        }

        $clockwiseDistance = ($targetIndex - $currentIndex + count($terrainCycle)) % count($terrainCycle);
        $counterclockwiseDistance = ($currentIndex - $targetIndex + count($terrainCycle)) % count($terrainCycle);

        return $clockwiseDistance < $counterclockwiseDistance
            ? $terrainCycle[($currentIndex + 1) % count($terrainCycle)]
            : $terrainCycle[($currentIndex - 1 + count($terrainCycle)) % count($terrainCycle)];
    }

    public function spadesTo(self $target): int
    {
        $terrain = $this;
        $spades = 0;

        while ($terrain !== $target && $terrain->isHomeland() && $target->isHomeland()) {
            $terrain = $terrain->stepTowards($target);
            $spades++;
        }

        return $spades;
    }

    public function description(): string
    {
        return match ($this) {
            self::Desert => 'Стартовая лопата после размещения начальных зданий.',
            self::Plains => 'Удешевлённое продвижение преобразования.',
            self::Swamp => 'Дополнительный учёный и стартовая сила: 9 в чаше II и 3 в чаше I.',
            self::Lake => 'Стартовый уровень судоходства 1.',
            self::Forest => 'По одному стартовому шагу во всех дисциплинах; стартовая сила: 8 в чаше II и 4 в чаше I.',
            self::Mountain => 'Дополнительный доход монет.',
            self::Wasteland => 'Дополнительная книга, инструмент и льгота второго изобретения.',
            self::Water => 'Водная ячейка, недоступная для строительства и преобразования.',
        };
    }
}
