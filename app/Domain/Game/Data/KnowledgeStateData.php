<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\KnowledgeDiscipline;
use Spatie\LaravelData\Data;

/**
 * @property int $banking Положение на шкале банковского дела.
 * @property int $law Положение на шкале права.
 * @property int $engineering Положение на шкале инженерного дела.
 * @property int $medicine Положение на шкале медицины.
 * @property list<KnowledgeDiscipline> $unlockedDisciplines Дисциплины, для которых потрачен городской ключ.
 * @property int $unassignedSteps Стартовые шаги знаний, дисциплину которых ещё нужно выбрать.
 */
class KnowledgeStateData extends Data
{
    /** @param list<KnowledgeDiscipline> $unlockedDisciplines */
    public function __construct(
        public int $banking = 0,
        public int $law = 0,
        public int $engineering = 0,
        public int $medicine = 0,
        public array $unlockedDisciplines = [],
        public int $unassignedSteps = 0,
    ) {
    }
}
