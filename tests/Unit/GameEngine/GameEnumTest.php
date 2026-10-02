<?php

declare(strict_types=1);

namespace Tests\Unit\GameEngine;

use App\Domain\GameEngine\Enums\EffectType;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GameEnumTest extends TestCase
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

    public function test_only_actions_is_the_action_phase(): void
    {
        foreach (GamePhase::cases() as $phase) {
            $this->assertSame($phase === GamePhase::Actions, $phase->isActionPhase());
        }
    }

    /** @return iterable<string, array{class-string<\BackedEnum>, list<int|string>}> */
    public static function gameEnumProvider(): iterable
    {
        yield 'типы эффектов' => [EffectType::class, [
            'immediate', 'income', 'pass', 'special_action', 'permanent',
            'round_scoring', 'knowledge_bonus',
        ]];
    }
}
