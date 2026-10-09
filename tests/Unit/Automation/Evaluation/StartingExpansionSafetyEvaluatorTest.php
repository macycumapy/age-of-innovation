<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\StartingExpansionSafetyEvaluator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class StartingExpansionSafetyEvaluatorTest extends TestCase
{
    public function test_border_start_is_worse_than_an_equally_open_interior_start_and_corner_is_worse_still(): void
    {
        $interior = $this->state();
        for ($index = 0; $index < 4; $index++) {
            $id = 'water-'.$index;
            $interior->board->hexes[] = new BoardHexStateData(
                id: $id,
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Water,
                terrain: TerrainType::Water,
            );
            $interior->board->hexes[0]->adjacentHexIds[] = $id;
        }
        $border = $interior->deepCopy();
        $border->board->edgeHexIds = ['root'];
        $border->board->hexes[0]->adjacentHexIds = ['gate-a', 'gate-b', 'water-0', 'water-1'];
        $corner = $border->deepCopy();
        $corner->board->hexes[0]->adjacentHexIds = ['gate-a', 'gate-b', 'water-0'];
        $evaluator = app(StartingExpansionSafetyEvaluator::class);

        $this->assertSame(-8, $evaluator->execute($interior, $interior->players[0]));
        $this->assertSame(-26, $evaluator->execute($border, $border->players[0]));
        $this->assertSame(-38, $evaluator->execute($corner, $corner->players[0]));
        $this->assertSame(['gate-a', 'gate-b', 'water-0', 'water-1'], $border->board->hexes[0]->adjacentHexIds);
    }

    public function test_border_penalty_does_not_apply_after_setup_or_to_neutral_buildings(): void
    {
        $state = $this->state();
        $state->board->edgeHexIds = ['root'];
        $state->board->hexes[0]->building->isNeutral = true;
        $evaluator = app(StartingExpansionSafetyEvaluator::class);
        $this->assertSame(0, $evaluator->execute($state, $state->players[0]));
        $state->board->hexes[0]->building->isNeutral = false;
        $state->round->phase = GamePhase::Actions;
        $this->assertSame(0, $evaluator->execute($state, $state->players[0]));
    }

    public function test_two_independent_exits_are_better_than_one_corner_exit(): void
    {
        $open = $this->state();
        $corner = $open->deepCopy();
        $corner->board->hexes[0]->adjacentHexIds = ['gate-a'];
        $evaluator = app(StartingExpansionSafetyEvaluator::class);

        $this->assertSame(-8, $evaluator->execute($open, $open->players[0]));
        $this->assertSame(-34, $evaluator->execute($corner, $corner->players[0]));
    }

    public function test_routes_merging_at_one_chokepoint_are_not_independent(): void
    {
        $state = $this->state();
        $state->board->hexes[4]->adjacentHexIds = ['root', 'exit-a'];
        $state->board->hexes[2]->adjacentHexIds[] = 'gate-b';

        $this->assertSame(-34, app(StartingExpansionSafetyEvaluator::class)->execute($state, $state->players[0]));
    }

    public function test_a_cheap_dead_end_is_worse_than_a_corner_with_an_open_exit(): void
    {
        $corner = $this->state();
        $corner->board->hexes[0]->adjacentHexIds = ['gate-a'];
        $deadEnd = $corner->deepCopy();
        $deadEnd->board->hexes[2]->adjacentHexIds = ['gate-a'];
        $evaluator = app(StartingExpansionSafetyEvaluator::class);

        $this->assertSame(-60, $evaluator->execute($deadEnd, $deadEnd->players[0]));
        $this->assertGreaterThan($evaluator->execute($deadEnd, $deadEnd->players[0]), $evaluator->execute($corner, $corner->players[0]));
    }

    public function test_expensive_terraforming_makes_the_same_exit_less_attractive(): void
    {
        $cheap = $this->state();
        $expensive = $cheap->deepCopy();
        $expensive->board->hexes[1]->terrain = TerrainType::Desert;
        $evaluator = app(StartingExpansionSafetyEvaluator::class);

        $this->assertLessThan($evaluator->execute($cheap, $cheap->players[0]), $evaluator->execute($expensive, $expensive->players[0]));
    }

    public function test_opponent_buildings_block_a_route_and_shipping_requires_investment(): void
    {
        $blocked = $this->state();
        $blocked->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 2);
        $evaluator = app(StartingExpansionSafetyEvaluator::class);
        $this->assertSame(-34, $evaluator->execute($blocked, $blocked->players[0]));

        $river = $this->state();
        $river->board->hexes[0]->adjacentHexIds = ['gate-b'];
        $river->board->hexes[0]->riverConnectedHexIds = ['gate-a'];
        $this->assertSame(-16, $evaluator->execute($river, $river->players[0]));
        $river->players[0]->shippingLevel = 1;
        $this->assertSame(-8, $evaluator->execute($river, $river->players[0]));
    }

    public function test_the_forecast_is_setup_only_and_does_not_mutate_the_state(): void
    {
        $state = $this->state();
        $snapshot = $state->toArray();
        app(StartingExpansionSafetyEvaluator::class)->execute($state, $state->players[0]);
        $this->assertSame($snapshot, $state->toArray());
        $state->round->phase = GamePhase::Actions;
        $this->assertSame(0, app(StartingExpansionSafetyEvaluator::class)->execute($state, $state->players[0]));
    }

    private function state(): GameStateData
    {
        $state = new GameStateData(
            players: [new GamePlayerStateData(playerId: 1, userId: null, color: PlayerColor::Green, faction: Faction::Blessed, homeland: TerrainType::Forest, roundBonus: RoundBonus::Coins)],
            round: new RoundStateData(phase: GamePhase::Setup),
        );
        $neighbors = [
            'root' => ['gate-a', 'gate-b'],
            'gate-a' => ['root', 'exit-a'],
            'exit-a' => ['gate-a', 'leaf-a1', 'leaf-a2'],
            'leaf-a1' => ['exit-a'],
            'gate-b' => ['root', 'exit-b'],
            'exit-b' => ['gate-b', 'leaf-b1', 'leaf-b2'],
            'leaf-a2' => ['exit-a'],
            'leaf-b1' => ['exit-b'],
            'leaf-b2' => ['exit-b'],
        ];
        foreach ($neighbors as $id => $adjacentHexIds) {
            $state->board->hexes[] = new BoardHexStateData(
                id: $id,
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                adjacentHexIds: $adjacentHexIds,
                building: $id === 'root' ? new BuildingStateData(BuildingType::Workshop, 1) : null,
            );
        }

        return $state;
    }
}
