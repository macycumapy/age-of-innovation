<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Data\StrategicPlanData;
use App\Domain\Automation\Enums\StrategicObjective;
use App\Domain\Automation\Evaluation\Services\CoinSpendingOpportunityEvaluator;
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

class CoinSpendingOpportunityEvaluatorTest extends TestCase
{
    public function test_surplus_coins_do_not_unlock_a_purchase_even_with_plenty_of_tools(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(coins: 42, tools: 5);
        $this->assertFalse($this->needed($state));
        $this->assertSame(42, $state->players[0]->resources->coins);
    }

    public function test_missing_coins_are_useful_when_the_other_upgrade_resources_are_available(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 2);
        $this->assertTrue($this->needed($state));
        $state->board->hexes[0]->building->type = BuildingType::Guild;
        $state->players[0]->resources->tools = 0;
        $this->assertFalse($this->needed($state));
    }

    public function test_next_income_is_not_a_reason_to_buy_coins_it_already_provides(): void
    {
        $state = $this->state();
        $state->round->number = 5;
        $state->board->hexes[0]->building->type = BuildingType::Guild;
        $state->players[0]->resources = new PlayerResourcesData(tools: 2, coins: 3);
        $this->assertFalse($this->needed($state));
        $state->players[0]->resources->coins = 0;
        $this->assertTrue($this->needed($state));
    }

    public function test_reserves_for_a_multi_step_plan_are_checked_as_well_as_individual_purchases(): void
    {
        $state = $this->state();
        $state->players[0]->resources->coins = 12;
        $plan = new StrategicPlanData(StrategicObjective::FoundTown, ['home'], [], 4, 15, 0, 100);
        $evaluator = app(CoinSpendingOpportunityEvaluator::class);
        $this->assertTrue($evaluator->execute($state, $state->players[0], $plan));
        $state->players[0]->resources->coins = 15;
        $this->assertFalse($evaluator->execute($state, $state->players[0], $plan));
    }

    private function needed(GameStateData $state): bool
    {
        return app(CoinSpendingOpportunityEvaluator::class)->execute($state, $state->players[0]);
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
                RoundBonus::Coins
            )],
            round: new RoundStateData(6, GamePhase::Actions),
        );
        $state->board->hexes = [new BoardHexStateData(
            'home',
            0,
            0,
            TerrainType::Forest,
            TerrainType::Forest,
            building: new BuildingStateData(BuildingType::Workshop, 1)
        )];

        return $state;
    }
}
