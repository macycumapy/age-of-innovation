<?php

declare(strict_types=1);

namespace App\Domain\Game\Data;

use App\Domain\Game\Enums\TerrainType;
use Spatie\LaravelData\Data;

/**
 * @property string $id Стабильный идентификатор гекса на игровой карте.
 * @property int $q Горизонтальная осевая координата гекса.
 * @property int $r Диагональная осевая координата гекса.
 * @property TerrainType $initialTerrain Исходный цвет местности на выбранной стороне карты.
 * @property TerrainType $terrain Текущий цвет местности после преобразований.
 * @property list<string> $adjacentHexIds Идентификаторы непосредственно соседних гексов.
 * @property list<string> $riverConnectedHexIds Идентификаторы гексов, доступных через соседний участок реки.
 * @property BuildingStateData|null $building Здание на гексе или null, если гекс свободен.
 * @property string|null $townId Идентификатор города, в который входит гекс.
 * @property string|null $townTileId Жетон города, размещённый на гексе.
 */
class BoardHexStateData extends Data
{
    /**
     * @param list<string> $adjacentHexIds
     * @param list<string> $riverConnectedHexIds
     */
    public function __construct(
        public string $id,
        public int $q,
        public int $r,
        public TerrainType $initialTerrain,
        public TerrainType $terrain,
        public array $adjacentHexIds = [],
        public array $riverConnectedHexIds = [],
        public ?BuildingStateData $building = null,
        public ?string $townId = null,
        public ?string $townTileId = null,
    ) {
    }
}
