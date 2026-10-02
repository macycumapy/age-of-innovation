<?php

declare(strict_types=1);

namespace App\Domain\GameEngine\Research\Data;

use App\Domain\GameEngine\State\Enums\PlayerColor;
use Spatie\LaravelData\Data;

/**
 * @property PlayerColor $color Цвет компонентов неигровой фракции.
 * @property KnowledgeStateData $knowledge Положение неигровой фракции на шкалах знаний.
 * @property list<string> $scholarDisciplineIds Дисциплины, в которых стоят учёные неигровой фракции.
 * @property int $scholarSlotIndex Место учёного в каждой дисциплине, начиная с нуля.
 */
class NeutralKnowledgeStateData extends Data
{
    /** @param list<string> $scholarDisciplineIds */
    public function __construct(
        public PlayerColor $color,
        public KnowledgeStateData $knowledge,
        public array $scholarDisciplineIds,
        public int $scholarSlotIndex = 1,
    ) {
    }
}
