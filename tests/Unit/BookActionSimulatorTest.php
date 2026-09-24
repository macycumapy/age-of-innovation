<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BookActionOptionData;
use App\Domain\Game\Data\BookPaymentData;
use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Domain\Game\Services\BookActionOptionFinder;
use App\Domain\Game\Services\BookActionSimulator;
use Tests\TestCase;

class BookActionSimulatorTest extends TestCase
{
    public function test_it_simulates_an_action_without_mutating_the_source_state(): void
    {
        $state = $this->state([BookAction::GainCoins]);
        $option = new BookActionOptionData(
            BookAction::GainCoins,
            new BookPaymentData(banking: 1, law: 1),
        );

        $simulation = app(BookActionSimulator::class)->execute($state, 1, $option);

        $this->assertNotSame($state, $simulation->state);
        $this->assertSame(0, $state->players[0]->resources->coins);
        $this->assertSame(1, $state->players[0]->resources->books->banking);
        $this->assertSame([], $state->round->usedBookActionIds);
        $this->assertFalse($state->round->hasTakenMainAction);
        $this->assertSame(6, $simulation->state->players[0]->resources->coins);
        $this->assertSame(0, $simulation->state->players[0]->resources->books->banking);
        $this->assertSame([BookAction::GainCoins->value], $simulation->state->round->usedBookActionIds);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    public function test_every_generated_option_can_be_simulated(): void
    {
        $state = $this->state(BookAction::cases(), booksPerDiscipline: 3);
        $options = app(BookActionOptionFinder::class)->execute($state, $state->players[0]);

        foreach ($options as $option) {
            $simulation = app(BookActionSimulator::class)->execute($state, 1, $option);

            $this->assertContains($option->action->value, $simulation->state->round->usedBookActionIds);
            $this->assertTrue($simulation->state->round->hasTakenMainAction);
        }

        $this->assertNotEmpty($options);
    }

    /** @param list<BookAction> $bookActions */
    private function state(array $bookActions, int $booksPerDiscipline = 1): GameStateData
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
                    adjacentHexIds: ['1:0'],
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['0:0'],
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
                    books: new BookSupplyData(
                        banking: $booksPerDiscipline,
                        law: $booksPerDiscipline,
                        engineering: $booksPerDiscipline,
                        medicine: $booksPerDiscipline,
                    ),
                ),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            setupPool: $setupPool,
        );
    }
}
