<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\DevelopmentInvestmentEvaluator;
use App\Domain\Automation\Evaluation\Services\GameStateEvaluator;
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

class DevelopmentInvestmentEvaluatorTest extends TestCase
{
    public function test_an_upgrade_values_funded_paid_digging_and_preserves_state(): void
    {
        $before = $this->state();
        $after = $before->deepCopy();
        $after->players[0]->terraformingLevel = 1;
        $evaluator = app(DevelopmentInvestmentEvaluator::class);

        $this->assertSame(60, $evaluator->execute($before, $after, 1));
        $this->assertSame(0, $before->players[0]->terraformingLevel);
        $this->assertSame(9, $after->players[0]->resources->tools);
        $after->players[0]->resources->tools = 3;
        $after->players[0]->resources->coins = 2;
        $this->assertSame(20, $evaluator->execute($before, $after, 1));
        $after->players[0]->resources->tools = 2;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    public function test_free_spades_and_homeland_targets_do_not_promise_paid_savings(): void
    {
        $before = $this->state();
        $after = $before->deepCopy();
        $after->players[0]->terraformingLevel = 1;
        $after->players[0]->unassignedSpades = 3;
        $evaluator = app(DevelopmentInvestmentEvaluator::class);
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
        $after->players[0]->unassignedSpades = 0;
        foreach ($after->board->hexes as $hex) {
            $hex->terrain = TerrainType::Forest;
        }
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    public function test_full_supply_and_missing_coins_prevent_speculative_savings(): void
    {
        $before = $this->state();
        $after = $before->deepCopy();
        $after->players[0]->terraformingLevel = 1;
        $after->players[0]->resources->coins = 0;
        $evaluator = app(DevelopmentInvestmentEvaluator::class);
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
        $after->players[0]->resources->coins = 6;
        for ($index = 1; $index < BuildingType::Workshop->supplyLimit(); $index++) {
            $after->board->hexes[] = new BoardHexStateData('used-'.$index, $index, 1, TerrainType::Forest, TerrainType::Forest, building: new BuildingStateData(BuildingType::Workshop, 1));
        }
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    public function test_levels_alone_have_no_intrinsic_development_reward(): void
    {
        $state = $this->state();
        $evaluator = app(GameStateEvaluator::class);
        $initial = $evaluator->evaluateWithBreakdown($state, 1)->development;
        $state->players[0]->shippingLevel = 3;
        $state->players[0]->terraformingLevel = 2;
        $this->assertSame($initial, $evaluator->evaluateWithBreakdown($state, 1)->development);
    }

    public function test_shipping_rewards_opening_funded_land_but_not_redundant_levels(): void
    {
        $before = $this->state();
        $before->board->hexes[0]->adjacentHexIds = ['a'];
        $before->board->hexes[1]->terrain = TerrainType::Water;
        $before->board->hexes[1]->adjacentHexIds = ['home', 'b'];
        $before->board->hexes[2]->terrain = TerrainType::Forest;
        $before->board->hexes[2]->adjacentHexIds = ['a'];
        $after = $before->deepCopy();
        $after->players[0]->shippingLevel = 1;
        $after->players[0]->resources->coins -= 4;
        $evaluator = app(DevelopmentInvestmentEvaluator::class);
        $this->assertSame(40, $evaluator->execute($before, $after, 1));
        $next = $after->deepCopy();
        $next->players[0]->shippingLevel = 2;
        $this->assertSame(0, $evaluator->execute($after, $next, 1));
        $after->players[0]->resources->coins = 1;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    public function test_shipping_opens_new_land_even_when_a_house_can_be_added_to_an_existing_town(): void
    {
        $before = $this->state();
        $before->board->hexes[0]->townId = 'existing';
        $before->board->hexes[0]->adjacentHexIds = ['a', 'b'];
        $before->board->hexes[1]->terrain = TerrainType::Forest;
        $before->board->hexes[2]->terrain = TerrainType::Water;
        $before->board->hexes[2]->adjacentHexIds = ['home', 'c'];
        $before->board->hexes[3]->terrain = TerrainType::Forest;
        $before->board->hexes[3]->adjacentHexIds = ['b'];
        $after = $before->deepCopy();
        $after->players[0]->shippingLevel = 1;
        $after->players[0]->resources->coins -= 4;
        $evaluator = app(DevelopmentInvestmentEvaluator::class);
        $this->assertSame(40, $evaluator->execute($before, $after, 1));
        $next = $after->deepCopy();
        $next->players[0]->shippingLevel = 2;
        $this->assertSame(0, $evaluator->execute($after, $next, 1));

        $before->players[0]->resources->scholars = 1;
        $withoutScholar = $before->deepCopy();
        $withoutScholar->players[0]->resources->scholars = 0;
        $this->assertSame(-40, app(PlayerEconomicNeedsEvaluator::class)->execute($before, $withoutScholar, 1));

        $before->board->hexes[0]->townId = null;
        $after->board->hexes[0]->townId = null;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    public function test_an_unused_bonus_spade_reduces_savings_only_until_it_is_spent(): void
    {
        $before = $this->state();
        $after = $before->deepCopy();
        $after->players[0]->terraformingLevel = 1;
        $after->players[0]->roundBonus = RoundBonus::Spade;
        $evaluator = app(DevelopmentInvestmentEvaluator::class);
        $this->assertSame(40, $evaluator->execute($before, $after, 1));
        $after->players[0]->usedSpecialActionIds = [RoundBonus::Spade->value];
        $this->assertSame(60, $evaluator->execute($before, $after, 1));
    }

    public function test_other_actions_and_setup_do_not_receive_an_investment_bonus(): void
    {
        $before = $this->state();
        $after = $before->deepCopy();
        $evaluator = app(DevelopmentInvestmentEvaluator::class);
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
        $after->players[0]->terraformingLevel = 1;
        $before->round->phase = GamePhase::Setup;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    private function state(): GameStateData
    {
        return new GameStateData(
            players: [new GamePlayerStateData(1, null, PlayerColor::Green, Faction::Blessed, TerrainType::Forest, RoundBonus::Coins, resources: new PlayerResourcesData(tools: 9, coins: 6))],
            round: new RoundStateData(number: 6, phase: GamePhase::Actions),
            board: new \App\Domain\GameEngine\Board\Data\BoardStateData(hexes: [
                new BoardHexStateData('home', 0, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['a', 'b', 'c'], building: new BuildingStateData(BuildingType::Workshop, 1)),
                new BoardHexStateData('a', 1, 0, TerrainType::Mountain, TerrainType::Mountain, adjacentHexIds: ['home']),
                new BoardHexStateData('b', 2, 0, TerrainType::Mountain, TerrainType::Mountain, adjacentHexIds: ['home']),
                new BoardHexStateData('c', 3, 0, TerrainType::Mountain, TerrainType::Mountain, adjacentHexIds: ['home']),
            ]),
        );
    }
}
