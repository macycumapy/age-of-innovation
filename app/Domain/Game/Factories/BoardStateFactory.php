<?php

declare(strict_types=1);

namespace App\Domain\Game\Factories;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Enums\MapVariant;
use App\Domain\Game\Enums\TerrainType;

final class BoardStateFactory
{
    /** @var list<array{int, int}> */
    private const array NEIGHBOUR_OFFSETS = [
        [1, 0], [1, -1], [0, -1],
        [-1, 0], [-1, 1], [0, 1],
    ];

    public function create(MapVariant $variant = MapVariant::ThreeToFivePlayers): BoardStateData
    {
        $terrainByCoordinate = $this->terrainByCoordinate($variant);

        $hexes = [];

        foreach ($terrainByCoordinate as $coordinate => $terrain) {
            [$q, $r] = array_map('intval', explode(':', $coordinate));

            $hexes[] = new BoardHexStateData(
                id: $coordinate,
                q: $q,
                r: $r,
                initialTerrain: $terrain,
                terrain: $terrain,
                adjacentHexIds: $this->adjacentHexIds($q, $r, $terrainByCoordinate),
            );
        }

        return new BoardStateData(variant: $variant, hexes: $hexes);
    }

    /**
     * @param array<string, TerrainType> $terrainByCoordinate
     * @return list<string>
     */
    private function adjacentHexIds(int $q, int $r, array $terrainByCoordinate): array
    {
        $adjacentHexIds = [];

        foreach (self::NEIGHBOUR_OFFSETS as [$qOffset, $rOffset]) {
            $coordinate = ($q + $qOffset) . ':' . ($r + $rOffset);

            if (isset($terrainByCoordinate[$coordinate])) {
                $adjacentHexIds[] = $coordinate;
            }
        }

        return $adjacentHexIds;
    }

    /** @return array<string, TerrainType> */
    private function terrainByCoordinate(MapVariant $variant): array
    {
        $rows = match ($variant) {
            MapVariant::OneToThreePlayers => $this->oneToThreePlayerRows(),
            MapVariant::ThreeToFivePlayers => $this->threeToFivePlayerRows(),
        };

        $terrainByCoordinate = [];

        foreach ($rows as $r => $row) {
            foreach ($row as $q => $terrain) {
                $terrainByCoordinate[$q . ':' . $r] = $terrain;
            }
        }

        return $terrainByCoordinate;
    }

    /** @return array<int, array<int, TerrainType>> */
    private function threeToFivePlayerRows(): array
    {
        return [
            0 => [0 => TerrainType::Forest, 2 => TerrainType::Lake, 3 => TerrainType::Swamp, 4 => TerrainType::Plains, 5 => TerrainType::Wasteland, 6 => TerrainType::Mountain, 8 => TerrainType::Lake, 9 => TerrainType::Swamp, 10 => TerrainType::Plains, 11 => TerrainType::Desert],
            1 => [0 => TerrainType::Mountain, 3 => TerrainType::Forest, 4 => TerrainType::Lake, 5 => TerrainType::Forest, 6 => TerrainType::Desert, 8 => TerrainType::Forest, 9 => TerrainType::Mountain, 10 => TerrainType::Lake, 12 => TerrainType::Swamp],
            2 => [-1 => TerrainType::Wasteland, 0 => TerrainType::Lake, 1 => TerrainType::Swamp, 3 => TerrainType::Mountain, 4 => TerrainType::Plains, 5 => TerrainType::Swamp, 8 => TerrainType::Wasteland, 11 => TerrainType::Wasteland],
            3 => [-1 => TerrainType::Desert, 0 => TerrainType::Mountain, 1 => TerrainType::Wasteland, 3 => TerrainType::Desert, 4 => TerrainType::Wasteland, 6 => TerrainType::Swamp, 9 => TerrainType::Plains, 10 => TerrainType::Mountain, 11 => TerrainType::Forest],
            4 => [-2 => TerrainType::Plains, -1 => TerrainType::Forest, 0 => TerrainType::Plains, 1 => TerrainType::Desert, 6 => TerrainType::Forest, 7 => TerrainType::Mountain, 8 => TerrainType::Lake, 9 => TerrainType::Wasteland, 10 => TerrainType::Desert, 11 => TerrainType::Plains],
            5 => [-3 => TerrainType::Swamp, 1 => TerrainType::Lake, 2 => TerrainType::Mountain, 4 => TerrainType::Desert, 5 => TerrainType::Plains, 8 => TerrainType::Swamp, 9 => TerrainType::Lake, 10 => TerrainType::Wasteland],
            6 => [-4 => TerrainType::Lake, -2 => TerrainType::Plains, -1 => TerrainType::Swamp, 1 => TerrainType::Forest, 2 => TerrainType::Plains],
            7 => [-3 => TerrainType::Desert, -2 => TerrainType::Lake, 0 => TerrainType::Wasteland, 1 => TerrainType::Swamp, 2 => TerrainType::Desert, 3 => TerrainType::Wasteland, 4 => TerrainType::Swamp, 5 => TerrainType::Lake, 6 => TerrainType::Forest, 7 => TerrainType::Swamp],
            8 => [-4 => TerrainType::Forest, -3 => TerrainType::Mountain, -2 => TerrainType::Wasteland, 0 => TerrainType::Desert, 1 => TerrainType::Mountain, 2 => TerrainType::Plains, 3 => TerrainType::Lake, 4 => TerrainType::Forest, 5 => TerrainType::Mountain, 6 => TerrainType::Wasteland, 7 => TerrainType::Desert, 8 => TerrainType::Plains],
        ];
    }

    /**
     * TODO сверить с оригиналом, вероятно какая-то строка лишняя
     * @return array<int, array<int, TerrainType>>
     */
    private function oneToThreePlayerRows(): array
    {
        return [
            0 => [1 => TerrainType::Forest, 2 => TerrainType::Mountain, 3 => TerrainType::Desert, 4 => TerrainType::Plains, 6 => TerrainType::Lake, 7 => TerrainType::Forest, 8 => TerrainType::Mountain, 9 => TerrainType::Wasteland],
            1 => [1 => TerrainType::Swamp, 2 => TerrainType::Lake, 3 => TerrainType::Swamp, 4 => TerrainType::Wasteland, 6 => TerrainType::Swamp, 7 => TerrainType::Plains, 8 => TerrainType::Lake, 10 => TerrainType::Forest],
            2 => [1 => TerrainType::Plains, 2 => TerrainType::Mountain, 3 => TerrainType::Forest, 6 => TerrainType::Desert, 9 => TerrainType::Desert],
            3 => [1 => TerrainType::Wasteland, 2 => TerrainType::Desert, 4 => TerrainType::Forest, 7 => TerrainType::Mountain, 8 => TerrainType::Plains, 9 => TerrainType::Swamp],
            5 => [4 => TerrainType::Swamp, 5 => TerrainType::Plains, 6 => TerrainType::Lake, 7 => TerrainType::Desert, 8 => TerrainType::Mountain],
            6 => [0 => TerrainType::Lake, 1 => TerrainType::Plains, 3 => TerrainType::Swamp, 4 => TerrainType::Mountain, 6 => TerrainType::Forest, 7 => TerrainType::Lake, 8 => TerrainType::Desert],
            7 => [0 => TerrainType::Swamp, 1 => TerrainType::Mountain],
            8 => [-1 => TerrainType::Desert, 0 => TerrainType::Forest, 1 => TerrainType::Wasteland, 2 => TerrainType::Desert, 3 => TerrainType::Forest, 4 => TerrainType::Lake, 5 => TerrainType::Swamp, 6 => TerrainType::Forest],
            9 => [-2 => TerrainType::Wasteland, -1 => TerrainType::Plains, 0 => TerrainType::Mountain, 1 => TerrainType::Lake, 2 => TerrainType::Swamp, 3 => TerrainType::Plains, 4 => TerrainType::Desert, 5 => TerrainType::Wasteland, 6 => TerrainType::Mountain],
        ];
    }
}
