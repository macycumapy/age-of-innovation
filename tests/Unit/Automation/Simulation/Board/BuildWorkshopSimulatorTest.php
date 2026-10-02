<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Board;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Board\Services\BuildWorkshopOptionFinder;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class BuildWorkshopSimulatorTest extends TestCase
{
    public function test_it_generates_and_simulates_workshop_building_without_mutating_the_source(): void
    {
        $state = $this->state();
        $options = app(BuildWorkshopOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertCount(1, $options);
        $this->assertSame('1:0', $options[0]->hexId);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertNull($state->board->hexes[1]->building);
        $this->assertSame(3, $state->players[0]->resources->tools);
        $this->assertSame(5, $state->players[0]->resources->coins);
        $this->assertFalse($state->round->hasTakenMainAction);
        $this->assertSame(BuildingType::Workshop, $simulation->state->board->hexes[1]->building->type);
        $this->assertSame(1, $simulation->state->board->hexes[1]->building->ownerPlayerId);
        $this->assertSame(2, $simulation->state->players[0]->resources->tools);
        $this->assertSame(3, $simulation->state->players[0]->resources->coins);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    private function state(): GameStateData
    {
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
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
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
                resources: new PlayerResourcesData(coins: 5, tools: 3),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
    }
}
