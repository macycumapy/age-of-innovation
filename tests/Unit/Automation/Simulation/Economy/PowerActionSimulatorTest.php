<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Economy;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Economy\Services\PowerActionOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
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

    public function test_it_does_not_offer_a_bridge_action_when_the_supply_is_exhausted(): void
    {
        $state = $this->state();
        $state->board = new BoardStateData(
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
            bridges: [
                new BridgeStateData('a', 'b', 1),
                new BridgeStateData('c', 'd', 1),
                new BridgeStateData('e', 'f', 1),
            ],
            riverBankHexIds: ['0:0', '1:1'],
        );

        $options = app(PowerActionOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertNull(collect($options)->firstWhere('action', PowerAction::BuildBridge));
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
