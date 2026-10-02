<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\PlayerEconomicNeedsEvaluator;
use Tests\TestCase;

class PlayerEconomicNeedsEvaluatorTest extends TestCase
{
    public function test_it_penalizes_spending_the_last_scholar_when_shipping_opens_land(): void
    {
        $before = $this->shippingState();
        $before->players[0]->resources->scholars = 1;
        $after = $before->deepCopy();
        $after->players[0]->resources->scholars = 0;

        $this->assertLessThan(0, app(PlayerEconomicNeedsEvaluator::class)->execute($before, $after, 1));
    }

    public function test_it_rewards_a_scholar_only_when_affordable_development_improves_the_map(): void
    {
        $before = $this->shippingState();
        $after = $before->deepCopy();
        $after->players[0]->resources->scholars = 1;
        $evaluator = app(PlayerEconomicNeedsEvaluator::class);

        $this->assertGreaterThan(0, $evaluator->execute($before, $after, 1));
        $before->players[0]->resources->coins = 0;
        $after->players[0]->resources->coins = 0;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    public function test_it_does_not_reserve_a_scholar_without_useful_development(): void
    {
        $before = $this->state();
        $before->players[0]->resources->scholars = 1;
        $after = $before->deepCopy();
        $after->players[0]->resources->scholars = 0;

        $this->assertSame(0, app(PlayerEconomicNeedsEvaluator::class)->execute($before, $after, 1));
    }

    public function test_it_does_not_penalize_sending_a_scholar_when_another_remains(): void
    {
        $before = $this->shippingState();
        $before->players[0]->resources->scholars = 2;
        $after = $before->deepCopy();
        $after->players[0]->resources->scholars = 1;

        $this->assertSame(0, app(PlayerEconomicNeedsEvaluator::class)->execute($before, $after, 1));
    }

    private function shippingState(): GameStateData
    {
        $state = $this->state();
        $state->board->hexes[0]->adjacentHexIds = ['water'];
        $state->board->hexes[] = new BoardHexStateData(
            id: 'water',
            q: 1,
            r: 0,
            initialTerrain: TerrainType::Water,
            terrain: TerrainType::Water,
            adjacentHexIds: ['0:0', 'target'],
        );
        $state->board->hexes[] = new BoardHexStateData(
            id: 'target',
            q: 2,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            adjacentHexIds: ['water'],
        );

        return $state;
    }

    public function test_it_values_tools_that_unlock_a_real_upgrade_over_more_coins(): void
    {
        $before = $this->state();
        $tools = $before->deepCopy();
        $tools->players[0]->resources->tools += 2;
        $coins = $before->deepCopy();
        $coins->players[0]->resources->coins += 7;
        $evaluator = app(PlayerEconomicNeedsEvaluator::class);

        $this->assertGreaterThan($evaluator->execute($before, $coins, 1), $evaluator->execute($before, $tools, 1));
        $this->assertSame(1, $before->players[0]->resources->tools);
    }

    public function test_it_does_not_reward_stockpiling_when_there_are_no_targets(): void
    {
        $before = $this->state();
        $before->board->hexes = [];
        $after = $before->deepCopy();
        $after->players[0]->resources->tools += 10;

        $this->assertSame(0, app(PlayerEconomicNeedsEvaluator::class)->execute($before, $after, 1));
    }

    public function test_it_caps_the_bonus_at_the_missing_resources(): void
    {
        $before = $this->state();
        $enough = $before->deepCopy();
        $enough->players[0]->resources->tools += 2;
        $excess = $before->deepCopy();
        $excess->players[0]->resources->tools += 20;
        $evaluator = app(PlayerEconomicNeedsEvaluator::class);

        $this->assertSame($evaluator->execute($before, $enough, 1), $evaluator->execute($before, $excess, 1));
    }

    public function test_it_values_tool_income_only_when_future_rounds_remain(): void
    {
        $before = $this->state();
        $after = $before->deepCopy();
        $after->players[0]->competencyIds = [\App\Domain\Game\Enums\Competency::Competency01->value];
        $evaluator = app(PlayerEconomicNeedsEvaluator::class);

        $this->assertGreaterThan(0, $evaluator->execute($before, $after, 1));

        $before->round->number = 6;
        $after->round->number = 6;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    private function state(): GameStateData
    {
        $state = new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(coins: 50, tools: 1),
            )],
            round: new RoundStateData(number: 1, phase: GamePhase::Actions),
        );
        $state->board->hexes = [new BoardHexStateData(
            id: '0:0',
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            building: new BuildingStateData(BuildingType::Workshop, 1),
        )];

        return $state;
    }
}
