<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\EffectType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PowerAction;
use App\Domain\Game\Enums\ResourceType;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TownTile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class GameEnumTest extends TestCase
{
    /**
     * @param class-string<\BackedEnum> $enum
     * @param list<string> $expectedValues
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

    /** @return iterable<string, array{class-string<\BackedEnum>, list<string>}> */
    public static function gameEnumProvider(): iterable
    {
        yield 'сообщества' => [Faction::class, [
            'blessed', 'felines', 'goblins', 'illusionists', 'inventors', 'lizards',
            'moles', 'monks', 'navigators', 'omar', 'philosophers', 'psychics',
        ]];
        yield 'местности' => [TerrainType::class, [
            'desert', 'plains', 'swamp', 'lake', 'forest', 'mountain', 'wasteland',
        ]];
        yield 'дисциплины' => [KnowledgeDiscipline::class, [
            'banking', 'law', 'engineering', 'medicine',
        ]];
        yield 'жетоны подсчёта раунда' => [RoundScoringTile::class, [
            'workshop_law', 'workshop_banking', 'guild_law', 'guild_medicine',
            'school_banking', 'palace_university_medicine', 'palace_university_banking',
            'spade_engineering', 'knowledge_medicine', 'town_engineering',
            'track_engineering', 'innovation_law',
        ]];
        yield 'цели раунда' => [RoundScoringGoal::class, [
            'workshop', 'guild', 'school', 'palace_or_university', 'spade',
            'knowledge', 'town', 'shipping_or_terraforming', 'innovation', 'edge_workshop',
        ]];
        yield 'изобретения' => [Innovation::class, [
            'deus_ex_machina', 'trade_routes', 'professor', 'sewage_system',
            'architecture', 'library', 'steam_engine', 'league_of_cities',
            'telecommunication', 'steel', 'census', 'science', 'workshop', 'guild',
            'school', 'university', 'palace', 'monument',
        ]];
        yield 'компетенции' => [Competency::class, self::numberedValues('competency', 12)];
        yield 'дворцы' => [PalaceAbility::class, self::numberedValues('palace', 17)];
        yield 'бонусы раунда' => [RoundBonus::class, [
            'river_workshop', 'send_scholar', 'build_guild', 'pass_palace_university',
            'spade', 'bridge', 'knowledge', 'pass_school', 'power_coins', 'coins',
        ]];
        yield 'действия книг' => [BookAction::class, [
            'gain_power', 'advance_knowledge', 'gain_coins', 'upgrade_to_guild',
            'score_guilds', 'terraform_three_spades',
        ]];
        yield 'действия силы' => [PowerAction::class, [
            'build_bridge', 'gain_scholar', 'gain_tools', 'gain_coins',
            'terraform_one_spade', 'terraform_two_spades',
        ]];
        yield 'жетоны города' => [TownTile::class, [
            'tools', 'terraform', 'books', 'coins', 'knowledge', 'power', 'scholar',
        ]];
        yield 'типы эффектов' => [EffectType::class, [
            'immediate', 'income', 'pass', 'special_action', 'permanent',
            'round_scoring', 'knowledge_bonus',
        ]];
        yield 'ресурсы' => [ResourceType::class, [
            'coin', 'tool', 'scholar', 'book', 'power', 'spade', 'victory_point',
        ]];
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
