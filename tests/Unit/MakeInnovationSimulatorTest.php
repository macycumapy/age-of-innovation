<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TownTile;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\MakeInnovationOptionFinder;
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
