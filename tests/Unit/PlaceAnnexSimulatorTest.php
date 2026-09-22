<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\PlaceAnnexOptionFinder;
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
        $this->assertSame(10, $simulation->nextActiveUserId);
    }
}
