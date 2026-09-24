<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\UpgradeBuildingOptionFinder;
use Tests\TestCase;

class UpgradeBuildingSimulatorTest extends TestCase
{
    public function test_it_generates_and_simulates_a_discounted_upgrade_without_mutating_the_source(): void
    {
        $state = $this->state();
        $options = app(UpgradeBuildingOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertCount(1, $options);
        $this->assertSame(BuildingType::Workshop, $options[0]->source);
        $this->assertSame(BuildingType::Guild, $options[0]->target);
        $this->assertSame(2, $options[0]->tools);
        $this->assertSame(3, $options[0]->coins);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(BuildingType::Workshop, $state->board->hexes[0]->building?->type);
        $this->assertSame(2, $state->players[0]->resources->tools);
        $this->assertSame(3, $state->players[0]->resources->coins);
        $this->assertSame(BuildingType::Guild, $simulation->state->board->hexes[0]->building->type);
        $this->assertSame(0, $simulation->state->players[0]->resources->tools);
        $this->assertSame(0, $simulation->state->players[0]->resources->coins);
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
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    building: new BuildingStateData(BuildingType::Workshop, 2),
                    adjacentHexIds: ['0:0'],
                ),
            ]),
            players: [
                new GamePlayerStateData(
                    playerId: 1,
                    userId: 10,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(coins: 3, tools: 2),
                ),
                new GamePlayerStateData(
                    playerId: 2,
                    userId: 20,
                    color: PlayerColor::Grey,
                    faction: Faction::Omar,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::Coins,
                ),
            ],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
    }
}
