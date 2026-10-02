<?php

declare(strict_types=1);

namespace Tests\Unit\GameEngine\Towns;

use App\Domain\GameEngine\Towns\Enums\TownTile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TownsEnumTest extends TestCase
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

    /** @return iterable<string, array{class-string<\BackedEnum>, list<int|string>}> */
    public static function gameEnumProvider(): iterable
    {
        yield 'жетоны города' => [TownTile::class, [
            'tools', 'terraform', 'books', 'coins', 'knowledge', 'power', 'scholar',
        ]];
    }
}
