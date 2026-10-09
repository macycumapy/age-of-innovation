<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\BoardPositionProgressEvaluator;
use App\Domain\Automation\Evaluation\Services\NetworkBlockingRiskEvaluator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
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

class NetworkBlockingRiskEvaluatorTest extends TestCase
{
    public function test_partial_digging_is_penalized_when_it_funds_an_opponents_capture(): void
    {
        $before = $this->state();
        $before->board->hexes[1]->terrain = TerrainType::Plains;
        $before->players[1]->homeland = TerrainType::Swamp;
        $before->players[1]->resources = new PlayerResourcesData(tools: 1, coins: 2);
        $after = $before->deepCopy();
        $after->board->hexes[1]->terrain = TerrainType::Swamp;
        $evaluator = app(NetworkBlockingRiskEvaluator::class);

        $this->assertSame(-40, $evaluator->execute($before, $after, 1));
        $after->players[1]->resources->tools = 0;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
        $after->players[1]->resources->tools = 1;
        $after->passedPlayerIds = [2];
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
        $after->passedPlayerIds = [];
        $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $this->assertGreaterThanOrEqual(0, $evaluator->execute($before, $after, 1));
        $this->assertSame(TerrainType::Plains, $before->board->hexes[1]->terrain);
    }

    public function test_claiming_a_contested_exit_preserves_future_expansion(): void
    {
        $before = $this->state();
        $after = $this->claimExit($before);
        $this->assertSame(80, app(NetworkBlockingRiskEvaluator::class)->execute($before, $after, 1));
        $this->assertNull($before->board->hexes[1]->building);
    }

    public function test_an_opponent_without_building_resources_is_not_an_immediate_threat(): void
    {
        $before = $this->state();
        $before->players[1]->resources->tools = 0;
        $this->assertSame(0, app(NetworkBlockingRiskEvaluator::class)->execute($before, $this->claimExit($before), 1));
    }

    public function test_terraforming_and_building_secures_a_contested_exit(): void
    {
        $before = $this->state();
        $before->board->hexes[1]->terrain = TerrainType::Plains;
        $before->players[1]->resources->tools = 10;
        $after = $this->claimExit($before);
        $after->board->hexes[1]->terrain = TerrainType::Forest;
        $this->assertSame(80, app(NetworkBlockingRiskEvaluator::class)->execute($before, $after, 1));
    }

    public function test_board_position_includes_the_urgency_of_claiming_the_exit(): void
    {
        $before = $this->state();
        $evaluator = app(BoardPositionProgressEvaluator::class);
        $threatenedScore = $evaluator->execute($before, $this->claimExit($before), 1);
        $before->players[1]->resources->tools = 0;
        $safeScore = $evaluator->execute($before, $this->claimExit($before), 1);
        $this->assertSame(80, $threatenedScore - $safeScore);
    }

    public function test_a_passed_opponent_does_not_create_urgency(): void
    {
        $before = $this->state();
        $before->passedPlayerIds = [2];
        $this->assertSame(0, app(NetworkBlockingRiskEvaluator::class)->execute($before, $this->claimExit($before), 1));
    }

    public function test_terraforming_without_building_does_not_secure_the_exit(): void
    {
        $before = $this->state();
        $before->board->hexes[1]->terrain = TerrainType::Plains;
        $after = $before->deepCopy();
        $after->board->hexes[1]->terrain = TerrainType::Forest;
        $this->assertSame(0, app(NetworkBlockingRiskEvaluator::class)->execute($before, $after, 1));
    }

    public function test_a_bridge_bypass_removes_the_bottleneck(): void
    {
        $before = $this->state();
        $before->board->bridges = [new BridgeStateData('home', 'far', 1)];
        $this->assertSame(0, app(NetworkBlockingRiskEvaluator::class)->execute($before, $this->claimExit($before), 1));
    }

    public function test_shipping_bypass_removes_the_bottleneck(): void
    {
        $before = $this->state();
        $before->players[0]->shippingLevel = 1;
        $before->board->hexes[0]->adjacentHexIds[] = 'water';
        $before->board->hexes[] = new BoardHexStateData('water', 0, 3, TerrainType::Water, TerrainType::Water, ['home', 'far']);
        $this->assertSame(0, app(NetworkBlockingRiskEvaluator::class)->execute($before, $this->claimExit($before), 1));
    }

    public function test_spending_a_turn_elsewhere_does_not_receive_a_safety_bonus(): void
    {
        $before = $this->state();
        $after = $before->deepCopy();
        $after->round->phase = GamePhase::ScienceBonus;
        $after->passedPlayerIds = [1, 2];
        $this->assertSame(0, app(NetworkBlockingRiskEvaluator::class)->execute($before, $after, 1));
    }

    private function claimExit(GameStateData $state): GameStateData
    {
        $after = $state->deepCopy();
        $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $after->round->hasTakenMainAction = true;

        return $after;
    }

    private function state(): GameStateData
    {
        $players = [];
        foreach ([1, 2] as $id) {
            $players[] = new GamePlayerStateData(
                $id,
                $id,
                $id === 1 ? PlayerColor::Green : PlayerColor::Red,
                Faction::Blessed,
                TerrainType::Forest,
                RoundBonus::Coins,
                resources: new PlayerResourcesData(coins: 10, tools: 5)
            );
        }
        $hexes = [];
        foreach (['home' => ['exit'], 'exit' => ['home', 'opponent', 'near'],
            'near' => ['exit', 'far'], 'far' => ['near'], 'opponent' => ['exit']] as $id => $adjacent) {
            $hexes[] = new BoardHexStateData(
                $id,
                0,
                count($hexes),
                TerrainType::Forest,
                TerrainType::Forest,
                $adjacent,
                building: match ($id) {
                    'home' => new BuildingStateData(BuildingType::Workshop, 1),
                    'opponent' => new BuildingStateData(BuildingType::Workshop, 2),
                    default => null,
                }
            );
        }

        return new GameStateData(
            players: $players,
            board: new BoardStateData(hexes: $hexes),
            round: new RoundStateData(number: 2, phase: GamePhase::Actions)
        );
    }
}
