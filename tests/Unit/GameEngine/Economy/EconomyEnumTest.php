<?php

declare(strict_types=1);

namespace Tests\Unit\GameEngine\Economy;

use App\Domain\GameEngine\Economy\Enums\BookAction;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Economy\Enums\ResourceType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EconomyEnumTest extends TestCase
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

    public function test_illusionists_pay_one_less_power_for_every_power_action(): void
    {
        foreach (PowerAction::cases() as $action) {
            $this->assertSame($action->cost() - 1, $action->cost(Faction::Illusionists));
            $this->assertSame($action->cost(), $action->cost(Faction::Blessed));
        }
    }

    #[DataProvider('illusionistPowerActionVictoryPointsProvider')]
    public function test_illusionists_gain_victory_points_from_power_actions(
        int $playerCount,
        int $expectedVictoryPoints,
    ): void {
        $action = PowerAction::GainTools;

        $this->assertSame(
            $expectedVictoryPoints,
            $action->victoryPoints(Faction::Illusionists, $playerCount),
        );
        $this->assertSame(0, $action->victoryPoints(Faction::Blessed, $playerCount));
    }

    /** @return iterable<string, array{int, int}> */
    public static function illusionistPowerActionVictoryPointsProvider(): iterable
    {
        yield '2 игрока' => [2, 1];
        yield '3 игрока' => [3, 1];
        yield '4 игрока' => [4, 2];
        yield '5 игроков' => [5, 2];
    }

    /** @return iterable<string, array{class-string<\BackedEnum>, list<int|string>}> */
    public static function gameEnumProvider(): iterable
    {
        yield 'действия книг' => [BookAction::class, [
            'gain_power', 'advance_knowledge', 'gain_coins', 'upgrade_to_guild',
            'score_guilds', 'terraform_three_spades',
        ]];
        yield 'действия силы' => [PowerAction::class, [
            'build_bridge', 'gain_scholar', 'gain_tools', 'gain_coins',
            'terraform_one_spade', 'terraform_two_spades',
        ]];
        yield 'ресурсы' => [ResourceType::class, [
            'coin', 'tool', 'scholar', 'book', 'power', 'spade', 'victory_point',
        ]];
    }
}
