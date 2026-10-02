<?php

declare(strict_types=1);

namespace Tests\Unit\GameEngine\Board;

use App\Domain\GameEngine\Board\Enums\TerrainType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BoardEnumTest extends TestCase
{
    /**
     * @param class-string<\BackedEnum> $enum
     * @param list<int|string> $expectedValues
     */
    #[DataProvider('gameEnumProvider')]
    public function test_game_enum_contains_expected_values(string $enum, array $expectedValues): void
    {
        $actualValues = array_map(
            static fn (\BackedEnum $case): int|string => $case->value,
            $enum::cases(),
        );

        $this->assertSame($expectedValues, $actualValues);
    }

    public function test_described_game_enums_have_descriptions(): void
    {
        foreach ([TerrainType::cases()] as $cases) {
            foreach ($cases as $case) {
                $this->assertNotSame('', $case->description());
            }
        }
    }

    public function test_terrain_moves_one_step_towards_desert(): void
    {
        $this->assertSame(TerrainType::Desert, TerrainType::Desert->stepTowards(TerrainType::Desert));
        $this->assertSame(TerrainType::Desert, TerrainType::Plains->stepTowards(TerrainType::Desert));
        $this->assertSame(TerrainType::Plains, TerrainType::Swamp->stepTowards(TerrainType::Desert));
        $this->assertSame(TerrainType::Swamp, TerrainType::Lake->stepTowards(TerrainType::Desert));
        $this->assertSame(TerrainType::Mountain, TerrainType::Forest->stepTowards(TerrainType::Desert));
        $this->assertSame(TerrainType::Wasteland, TerrainType::Mountain->stepTowards(TerrainType::Desert));
        $this->assertSame(TerrainType::Desert, TerrainType::Wasteland->stepTowards(TerrainType::Desert));
        $this->assertSame(TerrainType::Water, TerrainType::Water->stepTowards(TerrainType::Desert));
    }

    /** @return iterable<string, array{class-string<\BackedEnum>, list<int|string>}> */
    public static function gameEnumProvider(): iterable
    {
        yield 'местности' => [TerrainType::class, [
            'desert', 'plains', 'swamp', 'lake', 'forest', 'mountain', 'wasteland',
            'water',
        ]];
    }
}
