<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\BoardPositionProgressEvaluator;
use App\Domain\Automation\Evaluation\Services\DevelopmentInvestmentEvaluator;
use App\Domain\Automation\Evaluation\Services\PlayerEconomicNeedsEvaluator;
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

class PlayerEconomicNeedsEvaluatorTest extends TestCase
{
    public function test_losing_tool_income_is_costly_when_it_leaves_gold_without_construction_capacity(): void
    {
        $before = $this->state();
        $before->players[0]->resources = new PlayerResourcesData(coins: 50, tools: 3);
        $after = $before->deepCopy();
        $after->board->hexes[0]->building->type = BuildingType::Guild;
        $after->players[0]->resources = new PlayerResourcesData(coins: 44, tools: 1);
        $evaluator = app(PlayerEconomicNeedsEvaluator::class);

        $this->assertSame(-100, $evaluator->execute($before, $after, 1));
        $this->assertSame(BuildingType::Workshop, $before->board->hexes[0]->building->type);
        $before->round->number = 6;
        $after->round->number = 6;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    public function test_a_large_tool_reserve_can_cover_income_lost_to_an_upgrade(): void
    {
        $before = $this->state();
        $before->players[0]->resources = new PlayerResourcesData(coins: 50, tools: 30);
        $after = $before->deepCopy();
        $after->board->hexes[0]->building->type = BuildingType::Guild;
        $after->players[0]->resources = new PlayerResourcesData(coins: 44, tools: 28);

        $this->assertSame(0, app(PlayerEconomicNeedsEvaluator::class)->execute($before, $after, 1));
    }

    public function test_a_market_upgrade_keeps_its_value_when_the_remaining_economy_is_balanced(): void
    {
        $before = $this->state();
        $before->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 3);
        $after = $before->deepCopy();
        $after->board->hexes[0]->building->type = BuildingType::Guild;
        $after->players[0]->resources = new PlayerResourcesData(tools: 1);

        $this->assertSame(0, app(PlayerEconomicNeedsEvaluator::class)->execute($before, $after, 1));
    }

    public function test_an_upgrade_is_not_penalized_for_tool_income_it_does_not_actually_remove(): void
    {
        $before = $this->state();
        $before->players[0]->resources = new PlayerResourcesData(coins: 50, tools: 3);
        foreach (range(1, 4) as $index) {
            $before->board->hexes[] = new BoardHexStateData(
                (string) $index,
                $index,
                0,
                TerrainType::Forest,
                TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Workshop, 1)
            );
        }
        $after = $before->deepCopy();
        $after->board->hexes[0]->building->type = BuildingType::Guild;
        $after->players[0]->resources = new PlayerResourcesData(coins: 44, tools: 1);

        $this->assertSame(0, app(PlayerEconomicNeedsEvaluator::class)->execute($before, $after, 1));
    }

    public function test_it_values_missing_coins_only_when_remaining_tools_can_fund_construction(): void
    {
        $before = $this->state();
        $before->players[0]->resources = new PlayerResourcesData(coins: 1, tools: 2);
        $before->board->hexes[0]->adjacentHexIds = ['target'];
        $before->board->hexes[] = new BoardHexStateData('target', 1, 0, TerrainType::Forest, TerrainType::Forest);
        $after = $before->deepCopy();
        $after->players[0]->resources->coins++;
        $after->players[0]->resources->tools--;
        $evaluator = app(PlayerEconomicNeedsEvaluator::class);

        $this->assertGreaterThan(0, $evaluator->execute($before, $after, 1));
        $after->players[0]->resources->coins += 10;
        $this->assertSame(20, $evaluator->execute($before, $after, 1));
        $after->players[0]->resources->tools = 0;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
        $this->assertSame(1, $before->players[0]->resources->coins);
    }

    public function test_it_does_not_value_extra_coins_without_a_target_or_with_sufficient_coins(): void
    {
        $before = $this->state();
        $before->players[0]->resources->tools = 3;
        $after = $before->deepCopy();
        $after->players[0]->resources->coins++;
        $evaluator = app(PlayerEconomicNeedsEvaluator::class);
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
        $before->players[0]->resources->coins = 0;
        $after->players[0]->resources->coins = 1;
        $before->board->hexes = [];
        $after->board->hexes = [];
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    public function test_it_penalizes_spending_the_last_scholar_when_shipping_opens_land(): void
    {
        $before = $this->shippingState();
        $before->players[0]->resources->scholars = 1;
        $after = $before->deepCopy();
        $after->players[0]->resources->scholars = 0;

        $this->assertLessThan(0, app(PlayerEconomicNeedsEvaluator::class)->execute($before, $after, 1));
    }

    public function test_the_last_scholar_reserve_accounts_for_construction_cost_after_development(): void
    {
        $before = $this->shippingState();
        $before->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 4, scholars: 1);
        $before->board->hexes[2]->terrain = TerrainType::Mountain;
        $after = $before->deepCopy();
        $after->players[0]->resources->scholars = 0;
        $evaluator = app(PlayerEconomicNeedsEvaluator::class);

        $this->assertSame(-40, $evaluator->execute($before, $after, 1));
        $before->players[0]->resources->tools = 3;
        $after->players[0]->resources->tools = 3;
        $this->assertSame(-37, $evaluator->execute($before, $after, 1));
        $before->players[0]->resources->tools = 4;
        $after->players[0]->resources->tools = 4;
        $before->players[0]->resources->coins = 5;
        $after->players[0]->resources->coins = 5;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
        $before->players[0]->resources->coins = 6;
        $after->players[0]->resources->coins = 6;
        $before->players[0]->resources->tools = 3;
        $after->players[0]->resources->tools = 3;
        $before->round->number = 6;
        $after->round->number = 6;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
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

    public function test_completed_shipping_does_not_receive_a_second_bonus_for_the_same_frontier(): void
    {
        $before = $this->shippingState();
        $before->players[0]->resources->scholars = 1;
        $after = $before->deepCopy();
        $after->players[0]->shippingLevel = 1;
        $after->players[0]->resources->scholars = 0;
        $after->players[0]->resources->coins -= 4;

        $this->assertGreaterThan(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
        $investment = app(DevelopmentInvestmentEvaluator::class)->execute($before, $after, 1);
        $this->assertGreaterThan(0, $investment);
        $this->assertSame($investment, app(PlayerEconomicNeedsEvaluator::class)->execute($before, $after, 1));
    }

    public function test_a_development_reserve_does_not_value_one_scholar_as_three_scholars(): void
    {
        $before = $this->shippingState();
        $after = $before->deepCopy();
        $after->players[0]->resources->scholars = 1;
        $reserve = app(PlayerEconomicNeedsEvaluator::class)->execute($before, $after, 1);

        $this->assertGreaterThan(0, $reserve);
        $this->assertLessThanOrEqual(40, $reserve);
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
        $after->players[0]->competencyIds = [\App\Domain\GameEngine\Research\Enums\Competency::Competency01->value];
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
