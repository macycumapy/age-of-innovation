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
}
