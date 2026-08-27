<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Enums\MapVariant;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\BoardStateFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BoardStateFactoryTest extends TestCase
{
    #[DataProvider('mapVariants')]
    public function test_it_builds_a_complete_map_with_unique_coordinates(MapVariant $variant, int $expectedHexCount): void
    {
        $board = (new BoardStateFactory())->create($variant);
        $hexesById = collect($board->hexes)->keyBy('id');

        $this->assertSame($variant, $board->variant);
        $this->assertCount($expectedHexCount, $board->hexes);
        $this->assertCount($expectedHexCount, $hexesById);

        foreach ($board->hexes as $hex) {
            $this->assertSame($hex->q.':'.$hex->r, $hex->id);
            $this->assertSame($hex->initialTerrain, $hex->terrain);

            foreach ($hex->adjacentHexIds as $adjacentHexId) {
                $this->assertTrue($hexesById->has($adjacentHexId));
                $this->assertContains($hex->id, $hexesById->get($adjacentHexId)->adjacentHexIds);
            }
        }
    }

    public function test_large_map_contains_known_printed_hexes(): void
    {
        $hexes = collect((new BoardStateFactory())->create()->hexes)->keyBy('id');

        $this->assertSame(TerrainType::Forest, $hexes->get('0:0')->terrain);
        $this->assertSame(TerrainType::Desert, $hexes->get('11:0')->terrain);
        $this->assertSame(TerrainType::Plains, $hexes->get('8:8')->terrain);
    }

    public function test_small_map_contains_declared_river_bank_hexes(): void
    {
        $board = (new BoardStateFactory())->create(MapVariant::OneToThreePlayers);
        $hexIds = collect($board->hexes)->pluck('id');
        $expectedRiverBankHexIds = [
            '1:0', '1:1', '1:2', '1:3', '2:3', '3:2', '4:1', '4:0',
            '6:0', '6:1', '6:2', '7:1', '8:1', '9:0', '10:1', '9:2',
            '8:3', '7:3', '6:4', '5:4', '4:4', '4:3', '3:4', '3:5',
            '4:5', '6:5', '7:5', '8:5', '6:8', '6:7', '5:7', '4:7',
            '3:7', '2:7', '1:7', '1:6', '1:5', '0:5', '0:6', '-1:7',
            '-2:8',
        ];

        $this->assertSame($expectedRiverBankHexIds, $board->riverBankHexIds);

        foreach ($board->riverBankHexIds as $riverBankHexId) {
            $this->assertTrue($hexIds->contains($riverBankHexId));
        }
    }

    public function test_large_map_contains_declared_river_bank_hexes(): void
    {
        $board = (new BoardStateFactory())->create(MapVariant::ThreeToFivePlayers);
        $hexIds = collect($board->hexes)->pluck('id');
        $riverBankHexIds = [
            '0:0', '0:1', '0:2', '1:2', '1:3', '1:4', '0:4', '-1:4',
            '-2:5', '-3:6', '-4:8', '-3:8', '-2:7', '-1:6', '0:6', '-1:7',
            '-2:8', '0:8', '1:7', '2:6', '2:5', '3:5', '3:6', '3:7',
            '4:7', '5:7', '6:7', '7:7', '8:7', '8:8', '10:5', '9:5',
            '8:5', '8:4', '7:4', '6:5', '5:5', '5:4', '6:3', '6:4',
            '9:3', '10:3', '11:2', '12:1', '11:0', '10:1', '9:1', '8:2',
            '8:1', '8:0', '6:0', '6:1', '5:2', '4:3', '3:3', '3:2',
            '3:1', '3:0', '2:0',
        ];

        $this->assertSame($riverBankHexIds, $board->riverBankHexIds);

        foreach ($riverBankHexIds as $riverBankHexId) {
            $this->assertTrue($hexIds->contains($riverBankHexId));
        }
    }

    public function test_small_map_contains_declared_edge_hexes(): void
    {
        $board = (new BoardStateFactory())->create(MapVariant::OneToThreePlayers);
        $hexIds = collect($board->hexes)->pluck('id');
        $expectedEdgeHexIds = [
            '1:0', '2:0', '3:0', '4:0', '6:0', '7:0', '8:0', '9:0',
            '10:1', '9:2', '9:3', '8:4', '8:5', '6:8', '5:8', '4:8',
            '3:8', '2:8', '1:8', '0:8', '-1:8', '-2:8',
        ];

        $this->assertSame($expectedEdgeHexIds, $board->edgeHexIds);

        foreach ($board->edgeHexIds as $edgeHexId) {
            $this->assertTrue($hexIds->contains($edgeHexId));
        }
    }

    public function test_large_map_contains_declared_edge_hexes(): void
    {
        $board = (new BoardStateFactory())->create(MapVariant::ThreeToFivePlayers);
        $hexIds = collect($board->hexes)->pluck('id');
        $expectedEdgeHexIds = [
            '0:0', '2:0', '3:0', '4:0', '5:0', '6:0', '8:0', '9:0',
            '10:0', '11:0', '12:1', '11:2', '11:3', '10:4', '10:5', '8:8',
            '7:8', '6:8', '5:8', '4:8', '3:8', '2:8', '1:8', '0:8',
            '-2:8', '-3:8', '-4:8', '-3:6', '-2:5', '-2:4', '-1:3', '-1:2',
            '0:1',
        ];

        $this->assertSame($expectedEdgeHexIds, $board->edgeHexIds);

        foreach ($board->edgeHexIds as $edgeHexId) {
            $this->assertTrue($hexIds->contains($edgeHexId));
        }
    }

    /** @return iterable<string, array{MapVariant, int}> */
    public static function mapVariants(): iterable
    {
        yield '1–3 игрока' => [MapVariant::OneToThreePlayers, 59];
        yield '3–5 игроков' => [MapVariant::ThreeToFivePlayers, 81];
    }
}
