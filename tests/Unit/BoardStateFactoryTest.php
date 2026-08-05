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

    /** @return iterable<string, array{MapVariant, int}> */
    public static function mapVariants(): iterable
    {
        yield '1–3 игрока' => [MapVariant::OneToThreePlayers, 58];
        yield '3–5 игроков' => [MapVariant::ThreeToFivePlayers, 81];
    }
}
