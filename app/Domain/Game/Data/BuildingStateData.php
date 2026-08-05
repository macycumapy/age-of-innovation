<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\BuildingType;
use Spatie\LaravelData\Data;

/**
 * @property BuildingType $type Тип построенного здания.
 * @property int $ownerPlayerId Идентификатор игрока, которому принадлежит здание.
 * @property bool $isNeutral Используется ли нейтральная фигурка здания.
 * @property bool $hasAnnex Есть ли у здания пристройка.
 */
class BuildingStateData extends Data
{
    public function __construct(
        public BuildingType $type,
        public int $ownerPlayerId,
        public bool $isNeutral = false,
        public bool $hasAnnex = false,
    ) {
    }
}
