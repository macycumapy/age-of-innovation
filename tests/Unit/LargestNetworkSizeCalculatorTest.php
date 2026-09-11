<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BridgeStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\LargestNetworkSizeCalculator;
use PHPUnit\Framework\TestCase;

class LargestNetworkSizeCalculatorTest extends TestCase
{
    public function test_it_calculates_the_largest_network_using_adjacency_navigation_and_owned_bridges(): void
    {
        $board = new BoardStateData(
            hexes: [
                $this->landHex('a', ['b'], 1),
                $this->landHex('b', ['a', 'water-1'], 1),
                $this->waterHex('water-1', ['b', 'water-2']),
                $this->waterHex('water-2', ['water-1', 'c']),
                $this->landHex('c', ['water-2'], 1),
                $this->landHex('d', [], 1),
                $this->landHex('isolated', [], 1),
                $this->landHex('opponent', [], 2),
            ],
            bridges: [
                new BridgeStateData(fromHexId: 'c', toHexId: 'd', ownerPlayerId: 1),
                new BridgeStateData(fromHexId: 'd', toHexId: 'isolated', ownerPlayerId: 2),
            ],
        );

        $this->assertSame(4, LargestNetworkSizeCalculator::calculate($this->player(shippingLevel: 2), $board));
        $this->assertSame(2, LargestNetworkSizeCalculator::calculate($this->player(shippingLevel: 1), $board));
    }

    public function test_round_bonus_navigation_is_included_and_empty_network_is_zero(): void
    {
        $board = new BoardStateData(hexes: [
            $this->landHex('a', ['water'], 1),
            $this->waterHex('water', ['a', 'b']),
            $this->landHex('b', ['water'], 1),
        ]);
        $player = $this->player(shippingLevel: 0, roundBonus: RoundBonus::RiverWorkshop);

        $this->assertSame(2, LargestNetworkSizeCalculator::calculate($player, $board));
        $this->assertSame(1, LargestNetworkSizeCalculator::calculate($player, $board, includeRoundBonus: false));
        $this->assertSame(0, LargestNetworkSizeCalculator::calculate($player, new BoardStateData()));
    }

    public function test_moles_buildings_are_connected_through_tunnels_for_final_network(): void
    {
        $board = new BoardStateData(hexes: [
            new BoardHexStateData(
                id: '7:3',
                q: 7,
                r: 3,
                initialTerrain: TerrainType::Mountain,
                terrain: TerrainType::Mountain,
                adjacentHexIds: ['7:4'],
                building: new BuildingStateData(BuildingType::Workshop, 1),
            ),
            new BoardHexStateData(
                id: '7:4',
                q: 7,
                r: 4,
                initialTerrain: TerrainType::Water,
                terrain: TerrainType::Water,
                adjacentHexIds: ['7:3', '6:5'],
            ),
            new BoardHexStateData(
                id: '6:5',
                q: 6,
                r: 5,
                initialTerrain: TerrainType::Mountain,
                terrain: TerrainType::Mountain,
                adjacentHexIds: ['7:4'],
                building: new BuildingStateData(BuildingType::Workshop, 1),
            ),
        ]);

        $this->assertSame(2, LargestNetworkSizeCalculator::calculate(
            $this->player(shippingLevel: 0, faction: Faction::Moles),
            $board,
        ));
        $this->assertSame(1, LargestNetworkSizeCalculator::calculate($this->player(shippingLevel: 0), $board));
    }

    public function test_palace_nine_buildings_are_connected_by_flights_with_range_three(): void
    {
        $board = new BoardStateData(hexes: [
            $this->landHexAt('0:0', 0, 0, 1),
            $this->landHexAt('3:0', 3, 0, 1),
            $this->landHexAt('7:0', 7, 0, 1),
        ]);

        $this->assertSame(2, LargestNetworkSizeCalculator::calculate(
            $this->player(shippingLevel: 0, palaceId: PalaceAbility::Palace09->value),
            $board,
        ));
        $this->assertSame(1, LargestNetworkSizeCalculator::calculate($this->player(shippingLevel: 0), $board));
    }

    /** @param list<string> $adjacentHexIds */
    private function landHex(string $id, array $adjacentHexIds, int $ownerPlayerId): BoardHexStateData
    {
        return new BoardHexStateData(
            id: $id,
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Desert,
            terrain: TerrainType::Desert,
            adjacentHexIds: $adjacentHexIds,
            building: new BuildingStateData(BuildingType::Workshop, $ownerPlayerId),
        );
    }

    /** @param list<string> $adjacentHexIds */
    private function waterHex(string $id, array $adjacentHexIds): BoardHexStateData
    {
        return new BoardHexStateData(
            id: $id,
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Water,
            terrain: TerrainType::Water,
            adjacentHexIds: $adjacentHexIds,
        );
    }

    private function landHexAt(string $id, int $q, int $r, int $ownerPlayerId): BoardHexStateData
    {
        return new BoardHexStateData(
            id: $id,
            q: $q,
            r: $r,
            initialTerrain: TerrainType::Desert,
            terrain: TerrainType::Desert,
            building: new BuildingStateData(BuildingType::Workshop, $ownerPlayerId),
        );
    }

    private function player(
        int $shippingLevel,
        RoundBonus $roundBonus = RoundBonus::Coins,
        Faction $faction = Faction::Blessed,
        ?string $palaceId = null,
    ): GamePlayerStateData {
        return new GamePlayerStateData(
            playerId: 1,
            userId: 1,
            color: PlayerColor::Yellow,
            faction: $faction,
            homeland: TerrainType::Desert,
            roundBonus: $roundBonus,
            shippingLevel: $shippingLevel,
            palaceId: $palaceId,
        );
    }
}
