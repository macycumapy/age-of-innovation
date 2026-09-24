<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlaceBridgeOptionData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionOptionFinder;
use App\Domain\Game\Services\GameActionSimulator;
use Tests\TestCase;

class PlaceBridgeSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_a_confirmed_bridge_placement(): void
    {
        $state = new GameStateData(
            schemaVersion: 4,
            board: new BoardStateData(
                hexes: [
                    new BoardHexStateData(
                        id: '0:0',
                        q: 0,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        building: new BuildingStateData(BuildingType::Workshop, 1),
                    ),
                    new BoardHexStateData(
                        id: '1:1',
                        q: 1,
                        r: 1,
                        initialTerrain: TerrainType::Mountain,
                        terrain: TerrainType::Mountain,
                    ),
                    new BoardHexStateData(
                        id: '1:0',
                        q: 1,
                        r: 0,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                    new BoardHexStateData(
                        id: '0:1',
                        q: 0,
                        r: 1,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                ],
                riverBankHexIds: ['0:0', '1:1'],
            ),
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::PlaceBridge,
                1,
                [],
                ['source' => 'power'],
            ),
        );
        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof PlaceBridgeOptionData,
        ));

        $this->assertCount(1, $options);
        $this->assertSame(GameActionOptionType::PlaceBridge, $options[0]->type());
        $this->assertSame('0:0', $options[0]->fromHexId);
        $this->assertSame('1:1', $options[0]->toHexId);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame([], $state->board->bridges);
        $this->assertCount(1, $simulation->state->board->bridges);
        $this->assertSame('0:0', $simulation->state->board->bridges[0]->fromHexId);
        $this->assertSame('1:1', $simulation->state->board->bridges[0]->toHexId);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }
}
