<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\TerrainType;
use Tests\TestCase;

class GameStateDataTest extends TestCase
{
    public function test_it_creates_a_typed_game_snapshot_from_an_array(): void
    {
        $state = GameStateData::from([
            'turnOrder' => [10],
            'players' => [[
                'playerId' => 10,
                'userId' => 20,
                'color' => PlayerColor::Yellow->value,
                'faction' => Faction::Inventors->value,
                'homeland' => TerrainType::Desert->value,
            ]],
            'round' => [
                'number' => 2,
                'phase' => GamePhase::Actions->value,
            ],
        ]);

        $this->assertSame([10], $state->turnOrder);
        $this->assertSame(PlayerColor::Yellow, $state->players[0]->color);
        $this->assertSame(Faction::Inventors, $state->players[0]->faction);
        $this->assertSame(TerrainType::Desert, $state->players[0]->homeland);
        $this->assertSame(GamePhase::Actions, $state->round->phase);
    }
}
