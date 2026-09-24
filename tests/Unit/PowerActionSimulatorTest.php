<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\PowerAction;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\PowerActionOptionFinder;
use Tests\TestCase;

class PowerActionSimulatorTest extends TestCase
{
    public function test_it_generates_and_simulates_power_actions_without_mutating_the_source(): void
    {
        $state = $this->state();
        $options = app(PowerActionOptionFinder::class)->execute($state, $state->players[0]);
        $gainCoins = collect($options)->firstWhere('action', PowerAction::GainCoins);

        $this->assertNotNull($gainCoins);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $gainCoins);

        $this->assertSame(0, $state->players[0]->resources->coins);
        $this->assertSame(6, $state->players[0]->resources->power->bowlThree);
        $this->assertSame([], $state->round->usedSharedActionIds);
        $this->assertSame(7, $simulation->state->players[0]->resources->coins);
        $this->assertSame(2, $simulation->state->players[0]->resources->power->bowlThree);
        $this->assertSame([PowerAction::GainCoins->value], $simulation->state->round->usedSharedActionIds);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    public function test_every_generated_power_action_can_be_simulated(): void
    {
        $state = $this->state();
        $options = app(PowerActionOptionFinder::class)->execute($state, $state->players[0]);

        foreach ($options as $option) {
            $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);

            $this->assertContains($option->action->value, $simulation->state->round->usedSharedActionIds);
            $this->assertTrue($simulation->state->round->hasTakenMainAction);
        }

        $this->assertCount(5, $options);
    }

    private function state(): GameStateData
    {
        return new GameStateData(
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
                resources: new PlayerResourcesData(
                    power: new PowerBowlsStateData(bowlTwo: 4, bowlThree: 6),
                ),
            )],
        );
    }
}
