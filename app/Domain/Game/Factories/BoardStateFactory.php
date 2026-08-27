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

        return new BoardStateData(
            variant: $variant,
            hexes: $hexes,
            riverBankHexIds: $this->riverBankHexIds($variant),
            edgeHexIds: $this->edgeHexIds($variant),
        );
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

    /** @return list<string> */
    private function riverBankHexIds(MapVariant $variant): array
    {
        return match ($variant) {
            MapVariant::OneToThreePlayers => [
                '1:0', '1:1', '1:2', '1:3', '2:3', '3:2', '4:1', '4:0',
                '6:0', '6:1', '6:2', '7:1', '8:1', '9:0', '10:1', '9:2',
                '8:3', '7:3', '6:4', '5:4', '4:4', '4:3', '3:4', '3:5',
                '4:5', '6:5', '7:5', '8:5', '6:8', '6:7', '5:7', '4:7',
                '3:7', '2:7', '1:7', '1:6', '1:5', '0:5', '0:6', '-1:7',
                '-2:8',
            ],
            MapVariant::ThreeToFivePlayers => [
                '0:0', '0:1', '0:2', '1:2', '1:3', '1:4', '0:4', '-1:4',
                '-2:5', '-3:6', '-4:8', '-3:8', '-2:7', '-1:6', '0:6', '-1:7',
                '-2:8', '0:8', '1:7', '2:6', '2:5', '3:5', '3:6', '3:7',
                '4:7', '5:7', '6:7', '7:7', '8:7', '8:8', '10:5', '9:5',
                '8:5', '8:4', '7:4', '6:5', '5:5', '5:4', '6:3', '6:4',
                '9:3', '10:3', '11:2', '12:1', '11:0', '10:1', '9:1', '8:2',
                '8:1', '8:0', '6:0', '6:1', '5:2', '4:3', '3:3', '3:2',
                '3:1', '3:0', '2:0',
            ],
        };
    }

    /** @return list<string> */
    private function edgeHexIds(MapVariant $variant): array
    {
        return match ($variant) {
            MapVariant::OneToThreePlayers => [
                '1:0', '2:0', '3:0', '4:0', '6:0', '7:0', '8:0', '9:0',
                '10:1', '9:2', '9:3', '8:4', '8:5', '6:8', '5:8', '4:8',
                '3:8', '2:8', '1:8', '0:8', '-1:8', '-2:8',
            ],
            MapVariant::ThreeToFivePlayers => [
                '0:0', '2:0', '3:0', '4:0', '5:0', '6:0', '8:0', '9:0',
                '10:0', '11:0', '12:1', '11:2', '11:3', '10:4', '10:5', '8:8',
                '7:8', '6:8', '5:8', '4:8', '3:8', '2:8', '1:8', '0:8',
                '-2:8', '-3:8', '-4:8', '-3:6', '-2:5', '-2:4', '-1:3', '-1:2',
                '0:1',
            ],
        };
    }

    /** @return array<int, array<int, TerrainType>> */
    private function threeToFivePlayerRows(): array
    {
        return [
            0 => [0 => TerrainType::Forest, 2 => TerrainType::Lake, 3 => TerrainType::Swamp, 4 => TerrainType::Plains, 5 => TerrainType::Wasteland, 6 => TerrainType::Mountain, 8 => TerrainType::Lake, 9 => TerrainType::Swamp, 10 => TerrainType::Plains, 11 => TerrainType::Desert],
            1 => [0 => TerrainType::Mountain, 3 => TerrainType::Forest, 4 => TerrainType::Lake, 5 => TerrainType::Forest, 6 => TerrainType::Desert, 8 => TerrainType::Forest, 9 => TerrainType::Mountain, 10 => TerrainType::Lake, 12 => TerrainType::Swamp],
            2 => [-1 => TerrainType::Wasteland, 0 => TerrainType::Lake, 1 => TerrainType::Swamp, 3 => TerrainType::Mountain, 4 => TerrainType::Plains, 5 => TerrainType::Swamp, 8 => TerrainType::Wasteland, 11 => TerrainType::Wasteland],
            3 => [-1 => TerrainType::Desert, 0 => TerrainType::Mountain, 1 => TerrainType::Wasteland, 3 => TerrainType::Desert, 4 => TerrainType::Wasteland, 6 => TerrainType::Swamp, 9 => TerrainType::Plains, 10 => TerrainType::Mountain, 11 => TerrainType::Forest],
            4 => [-2 => TerrainType::Plains, -1 => TerrainType::Forest, 0 => TerrainType::Plains, 1 => TerrainType::Desert, 5 => TerrainType::Forest, 6 => TerrainType::Mountain, 7 => TerrainType::Lake, 8 => TerrainType::Wasteland, 9 => TerrainType::Desert, 10 => TerrainType::Plains],
            5 => [-2 => TerrainType::Swamp, 2 => TerrainType::Lake, 3 => TerrainType::Mountain, 5 => TerrainType::Desert, 6 => TerrainType::Plains, 8 => TerrainType::Swamp, 9 => TerrainType::Lake, 10 => TerrainType::Wasteland],
            6 => [-3 => TerrainType::Lake, -1 => TerrainType::Plains, 0 => TerrainType::Swamp, 2 => TerrainType::Forest, 3 => TerrainType::Plains],
            7 => [-2 => TerrainType::Desert, -1 => TerrainType::Lake, 1 => TerrainType::Wasteland, 2 => TerrainType::Swamp, 3 => TerrainType::Desert, 4 => TerrainType::Wasteland, 5 => TerrainType::Swamp, 6 => TerrainType::Lake, 7 => TerrainType::Forest, 8 => TerrainType::Swamp],
            8 => [-4 => TerrainType::Forest, -3 => TerrainType::Mountain, -2 => TerrainType::Wasteland, 0 => TerrainType::Desert, 1 => TerrainType::Mountain, 2 => TerrainType::Plains, 3 => TerrainType::Lake, 4 => TerrainType::Forest, 5 => TerrainType::Mountain, 6 => TerrainType::Wasteland, 7 => TerrainType::Desert, 8 => TerrainType::Plains],
        ];
    }

    /**
     * @return array<int, array<int, TerrainType>>
     */
    private function oneToThreePlayerRows(): array
    {
        return [
            0 => [1 => TerrainType::Forest, 2 => TerrainType::Mountain, 3 => TerrainType::Desert, 4 => TerrainType::Plains, 6 => TerrainType::Lake, 7 => TerrainType::Forest, 8 => TerrainType::Mountain, 9 => TerrainType::Wasteland],
            1 => [1 => TerrainType::Swamp, 2 => TerrainType::Lake, 3 => TerrainType::Swamp, 4 => TerrainType::Wasteland, 6 => TerrainType::Swamp, 7 => TerrainType::Plains, 8 => TerrainType::Lake, 10 => TerrainType::Forest],
            2 => [1 => TerrainType::Plains, 2 => TerrainType::Mountain, 3 => TerrainType::Forest, 6 => TerrainType::Desert, 9 => TerrainType::Desert],
            3 => [1 => TerrainType::Wasteland, 2 => TerrainType::Desert, 4 => TerrainType::Forest, 7 => TerrainType::Mountain, 8 => TerrainType::Plains, 9 => TerrainType::Swamp],
            4 => [3 => TerrainType::Swamp, 4 => TerrainType::Plains, 5 => TerrainType::Lake, 6 => TerrainType::Desert, 7 => TerrainType::Wasteland, 8 => TerrainType::Mountain],
            5 => [0 => TerrainType::Lake, 1 => TerrainType::Plains, 3 => TerrainType::Swamp, 4 => TerrainType::Mountain, 6 => TerrainType::Forest, 7 => TerrainType::Lake, 8 => TerrainType::Desert],
            6 => [0 => TerrainType::Swamp, 1 => TerrainType::Mountain],
            7 => [-1 => TerrainType::Desert, 0 => TerrainType::Forest, 1 => TerrainType::Wasteland, 2 => TerrainType::Desert, 3 => TerrainType::Forest, 4 => TerrainType::Lake, 5 => TerrainType::Swamp, 6 => TerrainType::Forest],
            8 => [-2 => TerrainType::Wasteland, -1 => TerrainType::Plains, 0 => TerrainType::Mountain, 1 => TerrainType::Lake, 2 => TerrainType::Swamp, 3 => TerrainType::Plains, 4 => TerrainType::Desert, 5 => TerrainType::Wasteland, 6 => TerrainType::Mountain],
        ];
    }
}
