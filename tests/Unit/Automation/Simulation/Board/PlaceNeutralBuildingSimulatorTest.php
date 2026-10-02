<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Board;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Data\PlaceNeutralBuildingOptionData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class PlaceNeutralBuildingSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_a_neutral_building_placement(): void
    {
        $state = new GameStateData(
            schemaVersion: 4,
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData(BuildingType::School, 1),
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
                resources: new PlayerResourcesData(tools: 3),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::PlaceNeutralBuilding,
                1,
                ['1:0'],
                [
                    'buildingType' => BuildingType::Tower->value,
                    'source' => 'competency',
                    'queuedBuiltHexIds' => ['0:0'],
                ],
            ),
        );
        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof PlaceNeutralBuildingOptionData,
        ));

        $this->assertCount(1, $options);
        $this->assertSame(GameActionOptionType::PlaceNeutralBuilding, $options[0]->type());
        $this->assertSame('1:0', $options[0]->hexId);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertNull($state->board->hexes[1]->building);
        $this->assertSame(TerrainType::Mountain, $state->board->hexes[1]->terrain);
        $this->assertSame(BuildingType::Tower, $simulation->state->board->hexes[1]->building?->type);
        $this->assertTrue($simulation->state->board->hexes[1]->building->isNeutral);
        $this->assertSame(TerrainType::Forest, $simulation->state->board->hexes[1]->terrain);
        $this->assertSame(0, $simulation->state->players[0]->resources->tools);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }
}
