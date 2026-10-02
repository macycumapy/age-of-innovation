<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Board;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Board\Services\PlaceAnnexOptionFinder;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class PlaceAnnexSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_annex_placement_without_mutating_the_source(): void
    {
        $state = new GameStateData(
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Workshop, 1),
            )]),
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                availableAnnexes: 1,
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
        $options = app(PlaceAnnexOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertCount(1, $options);
        $this->assertSame('0:0', $options[0]->hexId);
        $this->assertSame(GameActionOptionType::PlaceAnnex, $options[0]->type());

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertFalse($state->board->hexes[0]->building?->hasAnnex);
        $this->assertSame(1, $state->players[0]->availableAnnexes);
        $this->assertFalse($state->round->hasTakenMainAction);
        $this->assertTrue($simulation->state->board->hexes[0]->building?->hasAnnex);
        $this->assertSame(0, $simulation->state->players[0]->availableAnnexes);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }
}
