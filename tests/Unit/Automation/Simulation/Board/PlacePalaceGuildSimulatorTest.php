<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Board;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Data\PlacePalaceGuildOptionData;
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

class PlacePalaceGuildSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_palace_guild_placement(): void
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
                    building: new BuildingStateData(BuildingType::Palace, 1),
                ),
                new BoardHexStateData(
                    id: '5:5',
                    q: 5,
                    r: 5,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                ),
            ]),
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::PlacePalaceGuild,
                1,
                ['5:5'],
                ['palaceBuiltHexId' => '0:0', 'selectedHexId' => null],
            ),
        );

        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof PlacePalaceGuildOptionData,
        ));

        $this->assertCount(1, $options);
        $this->assertSame(GameActionOptionType::PlacePalaceGuild, $options[0]->type());
        $this->assertSame('5:5', $options[0]->hexId);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertNull($state->board->hexes[1]->building);
        $this->assertSame(PendingInteractionType::PlacePalaceGuild, $state->pendingInteraction?->type);
        $this->assertSame(BuildingType::Guild, $simulation->state->board->hexes[1]->building?->type);
        $this->assertSame(1, $simulation->state->board->hexes[1]->building->ownerPlayerId);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }
}
