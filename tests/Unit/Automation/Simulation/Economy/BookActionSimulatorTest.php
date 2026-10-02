<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Economy;

use App\Domain\Automation\Simulation\Economy\Services\BookActionSimulator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookActionOptionData;
use App\Domain\GameEngine\Economy\Data\BookPaymentData;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Enums\BookAction;
use App\Domain\GameEngine\Economy\Services\BookActionOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
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
