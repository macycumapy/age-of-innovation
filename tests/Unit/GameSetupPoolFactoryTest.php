<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Enums\FinalRoundScoringTile;
use App\Domain\Game\Enums\MapVariant;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\RoundScoringGoal;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Tests\TestCase;

final class GameSetupPoolFactoryTest extends TestCase
{
    #[DataProvider('playerCounts')]
    public function test_it_creates_complete_setup_pool(
        int $playerCount,
        MapVariant $expectedMapVariant,
        int $expectedInnovationCount,
    ): void {
        $pool = $this->factory(42)->create($playerCount);

        $this->assertSame($playerCount, $pool->playerCount);
        $this->assertSame($expectedMapVariant, $pool->mapVariant);
        $this->assertGreaterThanOrEqual(0, $pool->firstPlayerIndex);
        $this->assertLessThan($playerCount, $pool->firstPlayerIndex);
        $this->assertCount(6, $pool->roundScoringTiles);
        $this->assertInstanceOf(FinalRoundScoringTile::class, $pool->additionalFinalRoundGoal);
        $this->assertCount(3, $pool->bookActions);
        $this->assertCount(12, $pool->competencies);
        $this->assertCount($expectedInnovationCount, $pool->innovations);
        $this->assertCount($playerCount + 2, $pool->palaces);
        $this->assertContains(PalaceAbility::Palace17, $pool->palaces);
        $this->assertCount(7, $pool->planningBundles);
        $this->assertNotContains(
            TerrainType::Water,
            array_map(
                static fn (PlanningBundleData $bundle): TerrainType => $bundle->homeland,
                $pool->planningBundles,
            ),
        );
        $this->assertCount(3, $pool->availableRoundBonuses);
        $this->assertCount(7, $pool->townTiles);
        $this->assertSame($playerCount === 2, $pool->twoPlayerAreaTile !== null);

        $this->assertUniqueBackedEnums($pool->roundScoringTiles);
        $this->assertUniqueBackedEnums($pool->bookActions);
        $this->assertUniqueBackedEnums($pool->competencies);
        $this->assertUniqueBackedEnums($pool->innovations);
        $this->assertUniqueBackedEnums($pool->palaces);
        $this->assertUniqueBackedEnums(array_map(
            static fn (PlanningBundleData $bundle) => $bundle->faction,
            $pool->planningBundles,
        ));
        $this->assertUniqueBackedEnums(array_map(
            static fn (PlanningBundleData $bundle) => $bundle->roundBonus,
            $pool->planningBundles,
        ));
        $this->assertUniqueBackedEnums(array_map(
            static fn (RoundBonusOfferData $offer) => $offer->roundBonus,
            $pool->availableRoundBonuses,
        ));
        $this->assertSame([1, 1, 1], array_column($pool->availableRoundBonuses, 'coins'));
    }

    public function test_round_scoring_tiles_follow_setup_restrictions(): void
    {
        for ($seed = 1; $seed <= 50; $seed++) {
            $tiles = $this->factory($seed)->create(4)->roundScoringTiles;

            $this->assertNotSame(RoundScoringTile::SpadeEngineering, $tiles[4]);
            $this->assertNotSame(RoundScoringTile::SpadeEngineering, $tiles[5]);

            $disciplineCounts = [];

            foreach (array_slice($tiles, 0, 5) as $tile) {
                $discipline = $tile->knowledgeDiscipline()->value;
                $disciplineCounts[$discipline] = ($disciplineCounts[$discipline] ?? 0) + 1;
            }

            $this->assertLessThan(3, max($disciplineCounts));
        }
    }

    public function test_final_round_tile_does_not_repeat_sixth_round_building_type(): void
    {
        for ($seed = 1; $seed <= 50; $seed++) {
            $pool = $this->factory($seed)->create(4);
            $sixthRoundGoal = $pool->roundScoringTiles[5]->goal();

            if (in_array($sixthRoundGoal, [
                RoundScoringGoal::Workshop,
                RoundScoringGoal::Guild,
                RoundScoringGoal::School,
            ], true)) {
                $this->assertNotSame($sixthRoundGoal, $pool->additionalFinalRoundGoal->goal());
            }
        }
    }

    public function test_final_round_tiles_define_their_scoring_values(): void
    {
        $this->assertSame(
            [2, 3, 4, 3],
            array_map(
                static fn (FinalRoundScoringTile $tile): int => $tile->victoryPoints(),
                FinalRoundScoringTile::cases(),
            ),
        );

        $this->assertSame(RoundScoringGoal::Workshop, FinalRoundScoringTile::EdgeWorkshop->goal());
    }

    public function test_map_variant_can_be_selected_explicitly_for_three_players(): void
    {
        $pool = $this->factory(42)->create(3, MapVariant::OneToThreePlayers);

        $this->assertSame(MapVariant::OneToThreePlayers, $pool->mapVariant);
    }

    public function test_stored_seed_reproduces_setup_pool(): void
    {
        $firstPool = $this->factory(1)->createFromSeed(4, 'stored-game-seed');
        $secondPool = $this->factory(999)->createFromSeed(4, 'stored-game-seed');

        $this->assertSame($firstPool->toArray(), $secondPool->toArray());
    }

    public function test_it_rejects_unsupported_player_count(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->factory(42)->create(1);
    }

    /** @return iterable<string, array{int, MapVariant, int}> */
    public static function playerCounts(): iterable
    {
        yield '2 игрока' => [2, MapVariant::OneToThreePlayers, 6];
        yield '3 игрока' => [3, MapVariant::ThreeToFivePlayers, 8];
        yield '4 игрока' => [4, MapVariant::ThreeToFivePlayers, 10];
        yield '5 игроков' => [5, MapVariant::ThreeToFivePlayers, 12];
    }

    private function factory(int $seed): GameSetupPoolFactory
    {
        return new GameSetupPoolFactory(new Randomizer(new Mt19937($seed)));
    }

    /** @param list<\BackedEnum> $cases */
    private function assertUniqueBackedEnums(array $cases): void
    {
        $values = array_map(
            static fn (\BackedEnum $case): int|string => $case->value,
            $cases,
        );

        $this->assertCount(count($values), array_unique($values));
    }
}
