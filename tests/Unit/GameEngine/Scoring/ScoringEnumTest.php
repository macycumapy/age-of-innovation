<?php

declare(strict_types=1);

namespace Tests\Unit\GameEngine\Scoring;

use App\Domain\GameEngine\Scoring\Enums\FinalRoundScoringTile;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringGoal;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\Scoring\Enums\TwoPlayerTerritoryScore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ScoringEnumTest extends TestCase
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
        yield 'жетоны подсчёта раунда' => [RoundScoringTile::class, [
            'workshop_law', 'workshop_banking', 'guild_law', 'guild_medicine',
            'school_banking', 'palace_university_medicine', 'palace_university_banking',
            'spade_engineering', 'knowledge_medicine', 'town_engineering',
            'track_engineering', 'innovation_law',
        ]];
        yield 'цели раунда' => [RoundScoringGoal::class, [
            'workshop', 'guild', 'school', 'palace_or_university', 'spade',
            'knowledge', 'town', 'shipping_or_terraforming', 'innovation',
        ]];
        yield 'дополнительные жетоны шестого раунда' => [FinalRoundScoringTile::class, [
            'workshop', 'guild', 'school', 'edge_workshop',
        ]];
        yield 'территория неигровой фракции' => [TwoPlayerTerritoryScore::class, [12, 13, 14, 15]];
    }
}
