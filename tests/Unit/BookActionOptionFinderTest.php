<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Domain\Game\Services\BookActionOptionFinder;
use Tests\TestCase;

class BookActionOptionFinderTest extends TestCase
{
    public function test_it_builds_complete_executable_book_action_payloads(): void
    {
        $state = $this->state([
            BookAction::GainCoins,
            BookAction::AdvanceKnowledge,
            BookAction::UpgradeToGuild,
        ]);

        $options = app(BookActionOptionFinder::class)->execute($state, $state->players[0]);

        $gainCoins = collect($options)->where('action', BookAction::GainCoins)->values();
        $knowledge = collect($options)->where('action', BookAction::AdvanceKnowledge)->values();
        $upgrades = collect($options)->where('action', BookAction::UpgradeToGuild)->values();

        $this->assertCount(1, $gainCoins);
        $this->assertSame([
            'banking' => 1,
            'law' => 1,
            'engineering' => 0,
            'medicine' => 0,
        ], $gainCoins->first()->payment->counts());
        $this->assertCount(8, $knowledge);
        $this->assertEqualsCanonicalizing(
            array_column(KnowledgeDiscipline::cases(), 'value'),
            $knowledge->pluck('discipline.value')->unique()->all(),
        );
        $this->assertCount(2, $upgrades);
        $this->assertEqualsCanonicalizing(['0:0', '1:0'], $upgrades->pluck('hexId')->all());
    }

    public function test_it_excludes_used_actions_and_actions_without_a_valid_target(): void
    {
        $state = $this->state([BookAction::GainCoins, BookAction::UpgradeToGuild]);
        $state->round->usedBookActionIds = [BookAction::GainCoins->value];

        foreach ($state->board->hexes as $hex) {
            $hex->building = null;
        }

        $this->assertSame([], app(BookActionOptionFinder::class)->execute($state, $state->players[0]));
    }

    /** @param list<BookAction> $bookActions */
    private function state(array $bookActions): GameStateData
    {
        $setupPool = app(GameSetupPoolFactory::class)->create(2);
        $setupPool->bookActions = $bookActions;

        return new GameStateData(
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Workshop, 1),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Workshop, 1),
                ),
            ]),
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(
                    books: new BookSupplyData(banking: 1, law: 1),
                ),
            )],
            round: new RoundStateData(),
            setupPool: $setupPool,
        );
    }
}
