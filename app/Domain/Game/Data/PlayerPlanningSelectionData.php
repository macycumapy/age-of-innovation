<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use Spatie\LaravelData\Data;

/**
 * @property int $playerId Идентификатор участника, выбравшего комплект.
 * @property PlanningBundleData $bundle Выбранные местность, сообщество и бонус раунда.
 */
final class PlayerPlanningSelectionData extends Data
{
    public function __construct(
        public int $playerId,
        public PlanningBundleData $bundle,
    ) {
    }
}
