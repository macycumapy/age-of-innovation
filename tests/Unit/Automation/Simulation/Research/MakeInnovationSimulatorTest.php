<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Research;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Services\MakeInnovationOptionFinder;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class MakeInnovationSimulatorTest extends TestCase
{
    public function test_it_enumerates_payment_and_simulates_an_innovation_without_mutating_the_source(): void
    {
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'innovation-simulation');
        $setupPool->innovations[0] = Innovation::LeagueOfCities;
        $state = new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                townTileIds: [TownTile::Tools->value, TownTile::Coins->value],
                resources: new PlayerResourcesData(
                    coins: 10,
                    books: new BookSupplyData(banking: 2, law: 2, medicine: 1),
                ),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableInventionIds: [Innovation::LeagueOfCities->value],
            setupPool: $setupPool,
        );
        $options = app(MakeInnovationOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertCount(1, $options);
        $this->assertSame(Innovation::LeagueOfCities, $options[0]->innovation);
        $this->assertSame([
            'banking' => 2,
            'law' => 2,
            'engineering' => 0,
            'medicine' => 1,
        ], $options[0]->payment->counts());
        $this->assertSame(5, $options[0]->coins);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(10, $state->players[0]->resources->coins);
        $this->assertSame([], $state->players[0]->inventionIds);
        $this->assertSame([Innovation::LeagueOfCities->value], $state->availableInventionIds);
        $this->assertSame(5, $simulation->state->players[0]->resources->coins);
        $this->assertSame(0, $simulation->state->players[0]->resources->books->banking);
        $this->assertSame(0, $simulation->state->players[0]->resources->books->law);
        $this->assertSame(0, $simulation->state->players[0]->resources->books->medicine);
        $this->assertSame([Innovation::LeagueOfCities->value], $simulation->state->players[0]->inventionIds);
        $this->assertSame(30, $simulation->state->players[0]->victoryPoints);
        $this->assertSame([], $simulation->state->availableInventionIds);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }
}
