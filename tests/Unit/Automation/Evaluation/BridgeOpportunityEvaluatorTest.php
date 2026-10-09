<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\BridgeOpportunityEvaluator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class BridgeOpportunityEvaluatorTest extends TestCase
{
    public function test_a_bridge_from_a_guild_to_an_opponent_has_no_construction_follow_up(): void
    {
        $state = $this->state();
        $state->board->hexes[0]->building->type = BuildingType::Guild;
        $state->board->hexes[1]->building->ownerPlayerId = 2;
        $this->assertFalse($this->useful($state));
    }

    public function test_a_first_opponent_contact_can_unlock_a_discounted_guild(): void
    {
        $state = $this->state();
        $state->board->hexes[1]->building->ownerPlayerId = 2;
        $this->assertTrue($this->useful($state));
        $state->board->hexes[0]->adjacentHexIds = ['1:1'];
        $this->assertFalse($this->useful($state));
    }

    public function test_connecting_different_owned_groups_is_useful_but_a_redundant_bridge_is_not(): void
    {
        $state = $this->state();
        $this->assertTrue($this->useful($state));
        $state->board->hexes[0]->adjacentHexIds = ['1:1'];
        $state->board->hexes[1]->adjacentHexIds = ['0:0'];
        $this->assertFalse($this->useful($state));
    }

    public function test_empty_land_needs_a_funded_build_and_cannot_reuse_already_connected_land(): void
    {
        $state = $this->state();
        $state->board->hexes[1]->building = null;
        $this->assertTrue($this->useful($state));
        $state->players[0]->resources->tools = 0;
        $this->assertFalse($this->useful($state));
        $state->players[0]->resources->tools = 2;
        $state->board->hexes[0]->adjacentHexIds = ['1:1'];
        $this->assertFalse($this->useful($state));
    }

    private function useful(GameStateData $state): bool
    {
        return app(BridgeOpportunityEvaluator::class)->execute($state, $state->players[0], '0:0', '1:1');
    }

    private function state(): GameStateData
    {
        $state = new GameStateData(
            players: [new GamePlayerStateData(
                1,
                null,
                PlayerColor::Green,
                Faction::Blessed,
                TerrainType::Forest,
                RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 2, coins: 3)
            )],
            round: new RoundStateData(6, GamePhase::Actions),
        );
        $state->board->hexes = [
            new BoardHexStateData(
                '0:0',
                0,
                0,
                TerrainType::Forest,
                TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Workshop, 1)
            ),
            new BoardHexStateData(
                '1:1',
                1,
                1,
                TerrainType::Forest,
                TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Workshop, 1)
            ),
        ];

        return $state;
    }
}
