<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\TerrainType;
use Spatie\LaravelData\Data;

/**
 * @property string $id Стабильный идентификатор гекса на игровой карте.
 * @property TerrainType $terrain Текущий тип местности после преобразований.
 * @property BuildingStateData|null $building Здание на гексе или null, если гекс свободен.
 */
class BoardHexStateData extends Data
{
    public function __construct(
        public string $id,
        public TerrainType $terrain,
        public ?BuildingStateData $building = null,
    ) {
    }
}
