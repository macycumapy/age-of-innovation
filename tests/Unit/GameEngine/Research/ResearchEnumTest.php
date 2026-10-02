<?php

declare(strict_types=1);

namespace Tests\Unit\GameEngine\Research;

use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ResearchEnumTest extends TestCase
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
        foreach ([Competency::cases()] as $cases) {
            foreach ($cases as $case) {
                $this->assertNotSame('', $case->description());
            }
        }
    }

    /** @return iterable<string, array{class-string<\BackedEnum>, list<int|string>}> */
    public static function gameEnumProvider(): iterable
    {
        yield 'дисциплины' => [KnowledgeDiscipline::class, [
            'banking', 'law', 'engineering', 'medicine',
        ]];
        yield 'изобретения' => [Innovation::class, [
            'deus_ex_machina', 'trade_routes', 'professor', 'sewage_system',
            'architecture', 'library', 'steam_engine', 'league_of_cities',
            'telecommunication', 'steel', 'census', 'science', 'workshop', 'guild',
            'school', 'university', 'palace', 'monument',
        ]];
        yield 'компетенции' => [Competency::class, self::numberedValues('competency', 12)];
    }

    /** @return list<string> */
    private static function numberedValues(string $prefix, int $count): array
    {
        $values = [];

        for ($number = 1; $number <= $count; $number++) {
            $values[] = sprintf('%s_%02d', $prefix, $number);
        }

        return $values;
    }
}
