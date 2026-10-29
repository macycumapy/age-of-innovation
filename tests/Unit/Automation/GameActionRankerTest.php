<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Data\StrategicPlanData;
use App\Domain\Automation\Enums\StrategicApproach;
use App\Domain\Automation\Enums\StrategicObjective;
use App\Domain\Automation\Evaluation\Services\BoardPositionProgressEvaluator;
use App\Domain\Automation\Evaluation\Services\FinalScoringProgressEvaluator;
use App\Domain\Automation\Evaluation\Services\GameStateEvaluator;
use App\Domain\Automation\Evaluation\Services\PassValueEvaluator;
use App\Domain\Automation\Evaluation\Services\RoundScoringProgressEvaluator;
use App\Domain\Automation\Services\GameActionRanker;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Data\BuildWorkshopOptionData;
use App\Domain\GameEngine\Board\Data\PaidTerraformingOptionData;
use App\Domain\GameEngine\Board\Data\UpgradeBuildingOptionData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerActionOptionData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Economy\Data\ResourceExchangeOptionData;
use App\Domain\GameEngine\Economy\Enums\BookAction;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Data\RewardDistributionOptionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Data\RoundBonusOfferData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Research\Data\MakeInnovationOptionData;
use App\Domain\GameEngine\Research\Data\NeutralKnowledgeStateData;
use App\Domain\GameEngine\Research\Data\SendScholarOptionData;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Services\InnovationPurchaseCostCalculator;
use App\Domain\GameEngine\Scoring\Actions\ApplyFinalScoringAction;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use InvalidArgumentException;
use Tests\TestCase;

class GameActionRankerTest extends TestCase
{
    public function test_a_coin_rich_bot_prefers_a_new_workshop_to_an_unnecessary_income_upgrade(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 2;
        $state->players[0]->resources = new PlayerResourcesData(coins: 20, tools: 2);
        $state->board->hexes = [
            $this->buildingHex('home', 1, ['new-home']),
            $this->emptyHex('new-home', ['home']),
        ];
        $selected = app(GameActionRanker::class)->execute($state, 1, depth: 1)[0];

        $this->assertInstanceOf(BuildWorkshopOptionData::class, $selected->option);
        $this->assertSame('new-home', $selected->option->hexId);
    }

    public function test_shipping_does_not_get_a_position_bonus_for_unfunded_empty_land(): void
    {
        $before = $this->state();
        $before->round->number = 4;
        $before->board->hexes = [
            $this->buildingHex('home', 1, ['water']),
            $this->emptyHex('water', ['home', 'target'], TerrainType::Water),
            $this->emptyHex('target', ['water']),
        ];
        $after = $before->deepCopy();
        $after->players[0]->shippingLevel = 1;
        $evaluator = app(BoardPositionProgressEvaluator::class);
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
        $before->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 1);
        $after->players[0]->resources = new PlayerResourcesData(coins: 2, tools: 1);
        $this->assertGreaterThan(0, $evaluator->execute($before, $after, 1));
    }

    public function test_future_tool_income_does_not_make_current_gold_liquid(): void
    {
        $state = $this->state();
        $state->round->number = 1;
        $state->board->hexes = [
            $this->buildingHex('one', 1, []),
            $this->buildingHex('two', 1, []),
            $this->buildingHex('three', 1, []),
        ];
        $state->players[0]->resources = new PlayerResourcesData(coins: 30);
        $evaluator = app(GameStateEvaluator::class);
        $baseline = $evaluator->evaluateWithBreakdown($state, 1)->resources;
        $state->board->hexes[] = $this->buildingHex('four', 1, []);
        $this->assertSame($baseline, $evaluator->evaluateWithBreakdown($state, 1)->resources);

        $state->players[0]->resources->tools = 4;
        $funded = $evaluator->evaluateWithBreakdown($state, 1)->resources;
        $this->assertSame(4 * 30 + 6 * 8, $funded - $baseline);
        $state->round->number = 6;
        $funded = $evaluator->evaluateWithBreakdown($state, 1)->resources;
        $state->players[0]->resources->tools = 0;
        $this->assertSame(8, $funded - $evaluator->evaluateWithBreakdown($state, 1)->resources);
    }

    public function test_coin_power_actions_remain_available_for_final_scoring_and_direct_faction_points(): void
    {
        foreach ([[Faction::Blessed, 6], [Faction::Illusionists, 3]] as [$faction, $round]) {
            $state = $this->state();
            $state->round->number = $round;
            $state->turnOrder = [1];
            $state->players[0]->faction = $faction;
            $state->players[0]->resources = new PlayerResourcesData(
                coins: 42,
                power: new PowerBowlsStateData(bowlThree: 4)
            );
            $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);

            $this->assertNotNull(collect($ranked)->first(static fn ($candidate): bool =>
                $candidate->option instanceof PowerActionOptionData && $candidate->option->action === PowerAction::GainCoins));
        }
    }

    public function test_bridge_actions_are_excluded_when_the_only_bank_is_an_opponents_building(): void
    {
        $state = $this->state();
        $state->round->number = 4;
        $state->turnOrder = [1];
        $state->players[0]->roundBonus = RoundBonus::Bridge;
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 20,
            tools: 2,
            power: new PowerBowlsStateData(bowlThree: 3)
        );
        $state->board->hexes = [
            new BoardHexStateData(
                '0:0',
                0,
                0,
                TerrainType::Forest,
                TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Guild, 1)
            ),
            new BoardHexStateData(
                '1:1',
                1,
                1,
                TerrainType::Mountain,
                TerrainType::Mountain,
                building: new BuildingStateData(BuildingType::University, 2)
            ),
            new BoardHexStateData('1:0', 1, 0, TerrainType::Water, TerrainType::Water),
            new BoardHexStateData('0:1', 0, 1, TerrainType::Water, TerrainType::Water),
        ];
        $state->board->riverBankHexIds = ['0:0', '1:1'];
        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);
        $this->assertNotEmpty($ranked);
        foreach ($ranked as $candidate) {
            $this->assertFalse($candidate->option instanceof PowerActionOptionData
                && $candidate->option->action === PowerAction::BuildBridge);
            $this->assertNotSame(GameActionOptionType::UseRoundBonusAction, $candidate->option->type());
        }
    }

    public function test_tool_funding_beats_more_gold_and_pass_when_the_bot_has_surplus_coins(): void
    {
        $state = $this->state();
        $state->round->number = 2;
        $state->turnOrder = [1];
        $state->board->hexes = [$this->buildingHex('home', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::Guild;
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 31,
            power: new PowerBowlsStateData(bowlTwo: 8)
        );
        $state->round->usedSharedActionIds = array_map(
            static fn (PowerAction $action): string => $action->value,
            array_filter(PowerAction::cases(), static fn (PowerAction $action): bool =>
                ! in_array($action, [PowerAction::GainTools, PowerAction::GainCoins], true))
        );
        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);
        $tools = collect($ranked)->first(static fn ($candidate): bool =>
            $candidate->option instanceof PowerActionOptionData && $candidate->option->action === PowerAction::GainTools);
        $coins = collect($ranked)->first(static fn ($candidate): bool =>
            $candidate->option instanceof PowerActionOptionData && $candidate->option->action === PowerAction::GainCoins);
        $pass = collect($ranked)->first(static fn ($candidate): bool => $candidate->option->type() === GameActionOptionType::Pass);
        $this->assertNotNull($tools);
        $this->assertNull($coins);
        $this->assertNotNull($pass);
        $this->assertGreaterThan($pass->score, $tools->score);
    }

    public function test_a_strategic_build_is_evaluated_inside_its_funding_chain(): void
    {
        $state = $this->state();
        $state->round->number = 6;
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 2,
            power: new PowerBowlsStateData(bowlThree: 3)
        );
        $state->round->usedSharedActionIds = array_map(static fn (PowerAction $action): string => $action->value, PowerAction::cases());
        $state->board->hexes = [
            $this->buildingHex('left', 1, ['link']),
            $this->emptyHex('link', ['left', 'right']),
            $this->buildingHex('right', 1, ['link']),
        ];
        $plan = new StrategicPlanData(
            StrategicObjective::ConnectNetwork,
            ['left', 'right'],
            ['link'],
            1,
            2,
            0,
            100,
            targetId: 'link',
            approach: StrategicApproach::Build
        );
        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, strategicPlan: $plan);
        $funding = collect($ranked)->first(static fn ($candidate): bool =>
            $candidate->option instanceof ResourceExchangeOptionData
            && $candidate->option->exchange === \App\Domain\GameEngine\Economy\Enums\ResourceExchange::PowerToTool);

        $this->assertNotNull($funding);
        $this->assertSame(20, $funding->scoreBreakdown->strategicPlan);
        $this->assertNotEmpty($funding->continuation);
        $this->assertInstanceOf(BuildWorkshopOptionData::class, $funding->continuation[0]);
        $this->assertSame('link', $funding->continuation[0]->hexId);
        $this->assertSame(0, $state->players[0]->resources->tools);
    }

    public function test_surplus_gold_reduces_the_value_of_additional_recurring_coin_income(): void
    {
        $state = $this->state();
        $state->round->number = 3;
        $state->board->hexes = [$this->buildingHex('home', 1, [])];
        $state->players[0]->resources = new PlayerResourcesData(coins: 25);
        $withIncome = $state->deepCopy();
        $withIncome->players[0]->competencyIds[] = Competency::Competency02->value;
        $evaluator = app(GameStateEvaluator::class);
        $surplusIncomeValue = $evaluator->evaluateWithBreakdown($withIncome, 1)->futureIncome
            - $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;

        $state->players[0]->resources->coins = 0;
        $withIncome->players[0]->resources->coins = 0;
        $neededIncomeValue = $evaluator->evaluateWithBreakdown($withIncome, 1)->futureIncome
            - $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;

        $this->assertGreaterThan(0, $surplusIncomeValue);
        $this->assertGreaterThan($surplusIncomeValue, $neededIncomeValue);
    }

    public function test_next_bonus_values_surplus_gold_as_conversion_and_tools_as_spending_capacity(): void
    {
        $state = $this->state();
        $state->round->number = 4;
        $state->round->phase = GamePhase::ScienceBonus;
        $state->players[0]->resources = new PlayerResourcesData(coins: 22);
        $state->players[0]->roundBonus = RoundBonus::RiverWorkshop;
        $evaluator = app(GameStateEvaluator::class);
        $baseline = $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;

        $state->players[0]->roundBonus = RoundBonus::Coins;
        $this->assertSame(12, $evaluator->evaluateWithBreakdown($state, 1)->futureIncome - $baseline);
        $state->players[0]->roundBonus = RoundBonus::Knowledge;
        $this->assertSame(56, $evaluator->evaluateWithBreakdown($state, 1)->futureIncome - $baseline);
        $this->assertSame(22, $state->players[0]->resources->coins);
    }

    public function test_midgame_bonus_choice_repairs_tools_despite_coins_on_the_money_tile(): void
    {
        $state = $this->state();
        $state->round->number = 4;
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(coins: 9);
        $state->board->hexes = [$this->buildingHex('home', 1, [])];
        $state->passedPlayerIds = [1];
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'midgame-gold-without-tools');
        $state->round->scoringTileId = $state->setupPool->roundScoringTiles[3]->value;
        $state->setupPool->availableRoundBonuses = [
            new RoundBonusOfferData(RoundBonus::Coins, 3),
            new RoundBonusOfferData(RoundBonus::Knowledge, 0),
        ];
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChooseRoundBonus,
            1,
            [RoundBonus::Coins->value, RoundBonus::Knowledge->value],
        );

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, maxNodes: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(RoundBonus::Knowledge, $ranked[0]->option->roundBonus);
        $this->assertSame(9, $state->players[0]->resources->coins);
    }

    public function test_gold_above_liquidity_reserve_needs_tools_to_keep_working_value(): void
    {
        $state = $this->state();
        $state->round->number = 4;
        $state->players[0]->resources = new PlayerResourcesData(coins: 9);
        $evaluator = app(GameStateEvaluator::class);
        $before = $evaluator->evaluateWithBreakdown($state, 1)->resources;
        $state->players[0]->resources->coins++;
        $this->assertSame(2, $evaluator->evaluateWithBreakdown($state, 1)->resources - $before);

        $state->players[0]->resources = new PlayerResourcesData(coins: 9, tools: 4);
        $before = $evaluator->evaluateWithBreakdown($state, 1)->resources;
        $state->players[0]->resources->coins++;
        $this->assertSame(10, $evaluator->evaluateWithBreakdown($state, 1)->resources - $before);
    }

    public function test_funding_forecasts_reuse_equivalent_conversion_states(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->board->hexes = [];
        $state->round->usedSharedActionIds = array_map(static fn (PowerAction $action): string => $action->value, PowerAction::cases());
        $state->players[0]->resources = new PlayerResourcesData(tools: 3, power: new PowerBowlsStateData(bowlThree: 3));
        $ranker = app(GameActionRanker::class);
        $ranked = $ranker->execute($state, 1, depth: 1);

        $this->assertGreaterThan(0, $ranker->lastSearchTimings()->fundingForecastCacheHits);
        $this->assertSame(GameActionOptionType::Pass, $ranked[0]->option->type());
        $this->assertSame(3, $state->players[0]->resources->tools);
        $this->assertSame(3, $state->players[0]->resources->power->bowlThree);
    }

    public function test_deeper_root_search_keeps_pass_and_the_required_legal_continuation(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 6;
        $state->round->scoringTileId = RoundScoringTile::WorkshopLaw->value;
        $state->players[0]->resources = new PlayerResourcesData(coins: 10, tools: 5);
        $state->board->hexes = [$this->buildingHex('home', 1, ['a', 'b', 'c', 'd'])];
        foreach (['a', 'b', 'c', 'd'] as $id) {
            $state->board->hexes[] = $this->emptyHex($id, ['home']);
        }
        $ranker = app(GameActionRanker::class);
        $ranked = $ranker->execute(
            $state,
            1,
            depth: 2,
            branchLimit: 1,
            requiredRootOption: new BuildWorkshopOptionData('d')
        );

        $this->assertSame(2, $ranker->lastSearchTimings()->completedSearchDepth);
        $builds = array_filter($ranked, static fn ($action): bool => $action->option instanceof BuildWorkshopOptionData);
        $this->assertLessThanOrEqual(2, count($builds));
        $this->assertNotEmpty(array_filter($builds, static fn ($action): bool => $action->option->hexId === 'd'));
        $this->assertNotEmpty(array_filter($ranked, static fn ($action): bool => $action->option->type() === GameActionOptionType::Pass));
        foreach ($ranked as $action) {
            $this->assertSame($action->score, $action->scoreBreakdown->total());
        }
    }

    public function test_auxiliary_actions_without_a_funded_main_action_cannot_precede_a_pass(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 3;
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 20,
            power: new PowerBowlsStateData(bowlOne: 4, bowlTwo: 5),
        );
        $state->board->hexes = [$this->buildingHex('university', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::University;

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 3, maxNodes: 500);

        $this->assertSame(GameActionOptionType::Pass, $ranked[0]->option->type());
        $this->assertSame(5, $state->players[0]->resources->power->bowlTwo);
        $this->assertSame(20, $state->players[0]->resources->coins);
    }

    public function test_two_needed_exchanges_are_followed_by_the_funded_construction(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 5;
        $state->round->scoringTileId = RoundScoringTile::WorkshopLaw->value;
        $state->players[0]->resources = new PlayerResourcesData(coins: 0, tools: 9);
        $state->board->hexes = [$this->buildingHex('home', 1, ['target']), $this->emptyHex('target', ['home'])];
        $ranker = app(GameActionRanker::class);

        for ($remaining = 2; $remaining > 0; $remaining--) {
            $ranked = $ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: $remaining);
            $this->assertSame(GameActionOptionType::ExchangeResources, $ranked[0]->option->type());
            $this->assertCount($remaining, $ranked[0]->continuation);
            $this->assertInstanceOf(BuildWorkshopOptionData::class, $ranked[0]->continuation[$remaining - 1]);
            $state = $ranked[0]->simulation->state;
        }
        $ranked = $ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $this->assertSame(GameActionOptionType::BuildWorkshop, $ranked[0]->option->type());
        $this->assertSame(2, $state->players[0]->resources->coins);
        $this->assertSame(7, $state->players[0]->resources->tools);
    }

    public function test_a_funding_plan_does_not_exchange_resources_for_unprofitable_construction(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 5;
        $state->players[0]->resources = new PlayerResourcesData(coins: 0, tools: 9);
        $state->board->hexes = [$this->buildingHex('home', 1, ['target']), $this->emptyHex('target', ['home'])];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);

        $this->assertSame(GameActionOptionType::Pass, $ranked[0]->option->type());
        $this->assertSame(9, $state->players[0]->resources->tools);
    }

    public function test_an_auxiliary_action_keeps_its_plan_when_the_search_budget_runs_out(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 5;
        $state->round->scoringTileId = RoundScoringTile::WorkshopLaw->value;
        $state->players[0]->resources = new PlayerResourcesData(coins: 1, tools: 9);
        $state->board->hexes = [$this->buildingHex('home', 1, ['target']), $this->emptyHex('target', ['home'])];

        foreach ([1, 2, 3, 8, 200] as $maxNodes) {
            $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 2, maxNodes: $maxNodes);
            if ($ranked[0]->option->type() === GameActionOptionType::ExchangeResources) {
                $this->assertNotEmpty($ranked[0]->continuation, 'Missing funding plan with node limit '.$maxNodes);
            } else {
                $this->assertSame(GameActionOptionType::Pass, $ranked[0]->option->type());
            }
        }
    }

    public function test_three_needed_conversions_can_fund_a_profitable_library(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 6;
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 2,
            books: new BookSupplyData(banking: 3, law: 4),
            power: new PowerBowlsStateData(bowlThree: 4),
        );
        $state->players[0]->knowledge->banking = 12;
        $state->players[0]->knowledge->law = 12;
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'three-conversion-library');
        $state->setupPool->innovations[0] = Innovation::Library;
        $state->setupPool->bookActions = [];
        $state->availableInventionIds = [Innovation::Library->value];
        $state->round->usedSharedActionIds = array_map(static fn (PowerAction $action): string => $action->value, PowerAction::cases());

        $selected = app(GameActionRanker::class)->execute($state, 1, depth: 1)[0];

        $this->assertInstanceOf(ResourceExchangeOptionData::class, $selected->option);
        $this->assertCount(3, $selected->continuation);
        $this->assertInstanceOf(MakeInnovationOptionData::class, $selected->continuation[2]);
        $result = $selected->simulation;
        foreach ($selected->continuation as $option) {
            $result = app(\App\Domain\Automation\Services\GameActionSimulator::class)->execute($result->state, 1, $option);
        }
        $this->assertSame([Innovation::Library->value], $result->state->players[0]->inventionIds);
        $this->assertSame(24, $result->state->players[0]->victoryPoints - $state->players[0]->victoryPoints);
        $this->assertSame(2, $state->players[0]->resources->coins);
    }

    public function test_a_funding_plan_compares_a_second_conversion_with_cheap_immediate_construction(): void
    {
        $state = $this->townUpgradeState();
        $state->players[0]->resources = new PlayerResourcesData(coins: 6, power: new PowerBowlsStateData(bowlThree: 6));
        $state->players[0]->shippingLevel = 1;
        $state->board->hexes[0]->adjacentHexIds[] = 'water';
        $state->board->hexes[] = $this->emptyHex('water', ['a', 'remote'], TerrainType::Water);
        $state->board->hexes[] = $this->emptyHex('remote', ['water']);

        $selected = app(GameActionRanker::class)->execute($state, 1, depth: 1)[0];

        $this->assertInstanceOf(ResourceExchangeOptionData::class, $selected->option);
        $this->assertCount(2, $selected->continuation);
        $this->assertInstanceOf(UpgradeBuildingOptionData::class, $selected->continuation[1]);
        $this->assertSame(BuildingType::Guild, $selected->continuation[1]->target);
    }

    public function test_scholar_round_bonus_values_the_scholars_that_can_be_sent_before_passing(): void
    {
        $state = $this->state();
        $state->round->number = 6;
        $state->players[0]->resources->scholars = 2;
        $evaluator = app(GameStateEvaluator::class);
        $withoutBonus = $evaluator->evaluateWithBreakdown($state, 1)->development;
        $state->players[0]->roundBonus = RoundBonus::SendScholar;

        $this->assertSame(40, $evaluator->evaluateWithBreakdown($state, 1)->development - $withoutBonus);
        $state->passedPlayerIds = [1];
        $this->assertSame($withoutBonus, $evaluator->evaluateWithBreakdown($state, 1)->development);
    }

    public function test_scholar_round_bonus_forecasts_the_real_next_round_supply(): void
    {
        $state = $this->state();
        $state->round->number = 4;
        $state->round->phase = GamePhase::ScienceBonus;
        $state->players[0]->roundBonus = RoundBonus::SendScholar;
        $state->board->hexes = [
            $this->buildingHex('school-one', 1, []),
            $this->buildingHex('school-two', 1, []),
        ];
        foreach ($state->board->hexes as $hex) {
            $hex->building->type = BuildingType::School;
        }
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(60, $evaluator->evaluateWithBreakdown($state, 1)->development);
        $state->players[0]->scholarPoolSize = 2;
        $this->assertSame(40, $evaluator->evaluateWithBreakdown($state, 1)->development);
        $state->players[0]->scholarPoolSize = 0;
        $this->assertSame(0, $evaluator->evaluateWithBreakdown($state, 1)->development);
    }

    public function test_round_bonus_choice_prefers_needed_tools_to_coins_that_overflow_the_working_reserve(): void
    {
        $state = $this->state();
        $state->round->number = 2;
        $state->round->scoringTileId = RoundScoringTile::GuildLaw->value;
        $state->turnOrder = [1, 2];
        $opponent = $state->deepCopy()->players[0];
        $opponent->playerId = 2;
        $opponent->userId = 20;
        $state->players[] = $opponent;
        $state->players[0]->palaceId = PalaceAbility::Palace10->value;
        $state->players[0]->resources = new PlayerResourcesData(coins: 11);
        $state->passedPlayerIds = [1];
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'tools-not-surplus-bonus-coins');
        $state->setupPool->roundScoringTiles = array_fill(0, 6, RoundScoringTile::GuildLaw);
        $state->setupPool->availableRoundBonuses = [
            new RoundBonusOfferData(RoundBonus::Coins, 1),
            new RoundBonusOfferData(RoundBonus::Knowledge, 0),
        ];
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChooseRoundBonus,
            1,
            [RoundBonus::Coins->value, RoundBonus::Knowledge->value],
        );

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, maxNodes: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(RoundBonus::Knowledge, $ranked[0]->option->roundBonus);
        $this->assertSame(11, $state->players[0]->resources->coins);
    }

    public function test_competency_choice_repairs_tool_income_instead_of_adding_more_surplus_coins(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 2;
        $state->round->hasTakenMainAction = true;
        $state->players[0]->palaceId = PalaceAbility::Palace10->value;
        $state->players[0]->resources = new PlayerResourcesData(coins: 30);
        $state->availableCompetencyIds = [Competency::Competency01->value, Competency::Competency02->value];
        $state->board->hexes = [
            $this->buildingHex('palace', 1, ['school']),
            $this->buildingHex('school', 1, ['palace']),
        ];
        $state->board->hexes[0]->building->type = BuildingType::Palace;
        $state->board->hexes[1]->building->type = BuildingType::School;
        $state->pendingInteraction = new PendingInteractionData(
            type: PendingInteractionType::ChooseCompetency,
            playerId: 1,
            optionIds: $state->availableCompetencyIds,
            context: ['reason' => 'building', 'builtHexId' => 'school', 'powerOffersResolved' => true],
        );

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(Competency::Competency01, $ranked[0]->option->competency);
        $this->assertSame([], $state->players[0]->competencyIds);
    }

    public function test_final_school_pass_rewards_do_not_hide_a_profitable_action_before_passing(): void
    {
        $state = $this->state();
        $state->round->number = 6;
        $state->round->scoringTileId = RoundScoringTile::GuildLaw->value;
        $state->turnOrder = [1, 2];
        $opponent = $state->deepCopy()->players[0];
        $opponent->playerId = 2;
        $opponent->userId = 20;
        $state->players[] = $opponent;
        $state->passedPlayerIds = [2];
        $state->players[0]->roundBonus = RoundBonus::PassSchool;
        $state->players[0]->resources = new PlayerResourcesData(coins: 36, tools: 12);
        $state->players[0]->knowledge->engineering = 6;
        $state->players[0]->townTileIds = [TownTile::Coins->value];
        $state->availableCompetencyIds = [];
        $state->availablePalaceIds = [];
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'final-school-pass');
        $state->board->hexes = [
            $this->buildingHex('school-one', 1, ['school-two']),
            $this->buildingHex('school-two', 1, ['school-one', 'workshop']),
            $this->buildingHex('workshop', 1, ['school-two']),
        ];
        foreach ($state->board->hexes as $hex) {
            $hex->townId = 'existing-town';
        }
        $state->board->hexes[0]->building->type = BuildingType::School;
        $state->board->hexes[1]->building->type = BuildingType::School;

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, maxNodes: 25, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame(BuildingType::Guild, $ranked[0]->option->target);
        $this->assertGreaterThan(0, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertSame(GamePhase::Actions, $ranked[0]->simulation->state->round->phase);
        $this->assertSame([2], $ranked[0]->simulation->state->passedPlayerIds);
        $this->assertSame(36, $state->players[0]->resources->coins);
        $this->assertSame(6, $state->players[0]->knowledge->engineering);
    }

    public function test_final_scholar_bonus_is_collected_instead_of_lost_by_passing(): void
    {
        $state = $this->state();
        $state->round->number = 6;
        $state->round->scoringTileId = RoundScoringTile::GuildLaw->value;
        $state->turnOrder = [1, 2];
        $opponent = $state->deepCopy()->players[0];
        $opponent->playerId = 2;
        $opponent->userId = 20;
        $state->players[] = $opponent;
        $state->passedPlayerIds = [2];
        $state->players[0]->roundBonus = RoundBonus::SendScholar;
        $state->players[0]->resources = new PlayerResourcesData(scholars: 1);
        $state->players[0]->knowledge = new KnowledgeStateData(banking: 12, law: 12, engineering: 12, medicine: 12);
        $state->players[0]->townTileIds = [TownTile::Coins->value];
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'final-scholar-bonus');
        $state->board->hexes = [$this->buildingHex('workshop', 1, [])];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, maxNodes: 25, auxiliaryActionsRemaining: 0);

        $this->assertInstanceOf(SendScholarOptionData::class, $ranked[0]->option);
        $this->assertSame(22, $ranked[0]->simulation->state->players[0]->victoryPoints);
        $this->assertSame(GamePhase::Actions, $ranked[0]->simulation->state->round->phase);
        $this->assertSame(1, $state->players[0]->resources->scholars);
        $this->assertSame(20, $state->players[0]->victoryPoints);
    }

    public function test_a_small_budget_forecasts_a_newly_funded_town_upgrade_before_other_terraforming_branches(): void
    {
        $state = $this->state();
        $state->round->number = 2;
        $state->turnOrder = [1, 2];
        $opponent = $state->deepCopy()->players[0];
        $opponent->playerId = 2;
        $opponent->userId = 20;
        $state->players[] = $opponent;
        $state->players[0]->resources = new PlayerResourcesData(coins: 2, tools: 3);
        $state->availableTownTileIds = [TownTile::Coins->value];
        $state->availableCompetencyIds = [];
        $state->availablePalaceIds = [];
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'funded-town-upgrade');
        $state->setupPool->availableRoundBonuses = [new RoundBonusOfferData(RoundBonus::RiverWorkshop, 0)];
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['b']),
            $this->buildingHex('b', 1, ['a', 'c']),
            $this->buildingHex('c', 1, ['b', 'd']),
            $this->buildingHex('d', 1, ['c', 'opponent', 'target-0', 'target-1', 'target-2', 'target-3']),
            $this->buildingHex('opponent', 2, ['d']),
        ];
        $state->board->hexes[0]->building->type = BuildingType::School;
        $state->board->hexes[1]->building->type = BuildingType::Guild;
        for ($index = 0; $index < 4; $index++) {
            $state->board->hexes[] = $this->emptyHex('target-'.$index, ['d'], TerrainType::Mountain);
        }
        $ranker = app(GameActionRanker::class);

        $ranked = $ranker->execute($state, 1, depth: 1, maxNodes: 8);

        $this->assertSame(GameActionOptionType::ExchangeResources, $ranked[0]->option->type());
        $this->assertGreaterThan(100, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertLessThanOrEqual(8, $ranker->lastVisitedNodes());
        $this->assertSame(3, $ranked[0]->simulation->state->players[0]->resources->coins);
        $this->assertSame(BuildingType::Workshop, $ranked[0]->simulation->state->board->hexes[3]->building->type);
        $this->assertSame(2, $state->players[0]->resources->coins);
    }

    public function test_future_scholar_income_is_limited_by_the_remaining_physical_pool(): void
    {
        $state = $this->state();
        $state->round->number = 3;
        $state->board->hexes = [$this->buildingHex('school', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::School;
        $state->players[0]->scholarPoolSize = 0;
        $evaluator = app(GameStateEvaluator::class);
        $withoutPool = $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;
        $state->players[0]->scholarPoolSize = 1;

        $this->assertSame(63, $evaluator->evaluateWithBreakdown($state, 1)->futureIncome - $withoutPool);
        $state->board->hexes[] = $this->buildingHex('second-school', 1, []);
        $state->board->hexes[1]->building->type = BuildingType::School;
        $this->assertSame($withoutPool + 63, $evaluator->evaluateWithBreakdown($state, 1)->futureIncome);
    }

    public function test_the_first_scholar_income_uses_only_the_room_left_after_passing(): void
    {
        $state = $this->state();
        $state->round->number = 3;
        $state->players[0]->scholarPoolSize = 1;
        $state->board->hexes = [$this->buildingHex('school', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::School;
        $evaluator = app(GameStateEvaluator::class);
        $emptyHand = $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;
        $state->players[0]->resources->scholars = 1;
        $this->assertSame($emptyHand, $evaluator->evaluateWithBreakdown($state, 1)->futureIncome);

        foreach ([GamePhase::Actions, GamePhase::ScienceBonus, GamePhase::Setup] as $phase) {
            $state->round->phase = $phase;
            $state->passedPlayerIds = $phase === GamePhase::Actions ? [1] : [];
            $state->players[0]->resources->scholars = 0;
            $emptyHand = $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;
            $state->players[0]->resources->scholars = 1;

            $this->assertSame(40, $emptyHand - $evaluator->evaluateWithBreakdown($state, 1)->futureIncome);
        }
    }

    public function test_a_placed_scholar_has_no_separate_value_after_its_knowledge_reward(): void
    {
        $before = $this->state();
        $before->round->number = 3;
        $after = $before->deepCopy();
        $after->players[0]->scholarDisciplineIds = ['law'];
        $after->players[0]->scholarSlotIndexes = [0];
        $after->players[0]->scholarPoolSize--;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(
            $evaluator->evaluateWithBreakdown($before, 1)->development,
            $evaluator->evaluateWithBreakdown($after, 1)->development,
        );
    }

    public function test_returning_the_last_scholar_is_better_than_placing_it_for_the_same_knowledge(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 2;
        $state->players[0]->scholarPoolSize = 1;
        $state->players[0]->resources->scholars = 1;
        $state->players[0]->townTileIds = [TownTile::Coins->value];
        $state->players[0]->knowledge = new KnowledgeStateData(banking: 12, law: 12, engineering: 11, medicine: 12);
        $state->board->hexes = [$this->buildingHex('school', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::School;

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $returned = collect($ranked)->first(static fn ($action): bool => $action->option instanceof SendScholarOptionData
            && $action->option->discipline->value === 'engineering' && ! $action->option->place);
        $placed = collect($ranked)->first(static fn ($action): bool => $action->option instanceof SendScholarOptionData
            && $action->option->discipline->value === 'engineering' && $action->option->place);
        $this->assertNotNull($returned);
        $this->assertNotNull($placed);
        $this->assertGreaterThan($placed->score, $returned->score);
        $this->assertSame(1, $returned->simulation->state->players[0]->scholarPoolSize);
        $this->assertSame(1, $state->players[0]->resources->scholars);
    }

    public function test_a_round_bonus_does_not_forecast_extra_scholars_when_regular_income_fills_the_pool(): void
    {
        $state = $this->state();
        $state->round->number = 3;
        $state->passedPlayerIds = [1];
        $state->players[0]->scholarPoolSize = 1;
        $state->board->hexes = [$this->buildingHex('school', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::School;
        $evaluator = app(GameStateEvaluator::class);
        $coinBonusIncome = $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;
        $state->players[0]->roundBonus = RoundBonus::SendScholar;

        $this->assertSame(-60, $evaluator->evaluateWithBreakdown($state, 1)->futureIncome - $coinBonusIncome);
    }

    public function test_building_against_an_existing_town_does_not_forecast_a_second_town(): void
    {
        $before = $this->townUpgradeState();
        foreach (array_slice($before->board->hexes, 0, 3) as $hex) {
            $hex->townId = 'existing-town';
        }
        $after = $before->deepCopy();
        $after->board->hexes[3]->building->type = BuildingType::Guild;

        $this->assertSame(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_expansion_that_would_join_an_old_town_does_not_forecast_a_new_town(): void
    {
        $before = $this->state();
        $before->round->number = 4;
        $before->board->hexes = [
            $this->buildingHex('a', 1, ['b']),
            $this->buildingHex('b', 1, ['a', 'gap']),
            $this->emptyHex('gap', ['b', 'extra', 'university']),
            $this->emptyHex('extra', ['gap']),
            $this->buildingHex('university', 1, ['gap', 'old-school', 'old-guild']),
            $this->buildingHex('old-school', 1, ['university']),
            $this->buildingHex('old-guild', 1, ['university']),
        ];
        $before->board->hexes[4]->building->type = BuildingType::University;
        $before->board->hexes[5]->building->type = BuildingType::School;
        $before->board->hexes[6]->building->type = BuildingType::Guild;
        foreach (array_slice($before->board->hexes, 4) as $hex) {
            $hex->townId = 'old-town';
        }
        $after = $before->deepCopy();
        $after->board->hexes[0]->building->type = BuildingType::Guild;

        $this->assertSame(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));

        $before->board->hexes[2]->adjacentHexIds = ['b', 'extra'];
        $before->board->hexes[4]->adjacentHexIds = ['old-school', 'old-guild'];
        foreach ([['gap', 'university'], ['university', 'gap']] as [$fromHexId, $toHexId]) {
            $before->board->bridges = [new BridgeStateData($fromHexId, $toHexId, 1)];
            $after = $before->deepCopy();
            $after->board->hexes[0]->building->type = BuildingType::Guild;
            $this->assertSame(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));

            $before->board->bridges[0]->ownerPlayerId = 2;
            $after->board->bridges[0]->ownerPlayerId = 2;
            $this->assertSame(16, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
        }
    }

    public function test_available_annexes_are_valued_when_they_complete_a_town(): void
    {
        $before = $this->townUpgradeState();
        array_pop($before->board->hexes);
        $before->board->hexes[2]->adjacentHexIds = ['b'];
        $before->board->hexes[2]->building->type = BuildingType::Guild;
        $after = $before->deepCopy();
        $after->players[0]->availableAnnexes = 1;
        $evaluator = app(BoardPositionProgressEvaluator::class);

        $this->assertSame(336, $evaluator->execute($before, $after, 1));
        foreach ($after->board->hexes as $hex) {
            $hex->townId = 'existing-town';
        }
        $withoutAnnex = $after->deepCopy();
        $withoutAnnex->players[0]->availableAnnexes = 0;
        $this->assertSame(0, $evaluator->execute($withoutAnnex, $after, 1));
    }

    public function test_an_annex_in_a_completed_town_network_has_no_independent_value(): void
    {
        $before = $this->townUpgradeState();
        $before->round->number = 3;
        $before->board->hexes[0]->building->type = BuildingType::University;
        $before->board->hexes[1]->building->type = BuildingType::School;
        $before->board->hexes[2]->building->type = BuildingType::Guild;
        foreach (array_slice($before->board->hexes, 0, 3) as $hex) {
            $hex->townId = 'completed-town';
        }
        $before->players[0]->availableAnnexes = 1;
        $after = $before->deepCopy();
        $after->players[0]->availableAnnexes = 0;
        $after->board->hexes[3]->building->hasAnnex = true;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(
            $evaluator->evaluateWithBreakdown($before, 1)->buildings,
            $evaluator->evaluateWithBreakdown($after, 1)->buildings
        );
        $this->assertSame(-120, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_an_early_annex_is_strongly_penalized_when_a_cheap_exit_can_extend_the_network(): void
    {
        $before = $this->state();
        $before->round->number = 2;
        $before->players[0]->availableAnnexes = 1;
        $before->players[0]->resources = new PlayerResourcesData(coins: 2, tools: 4);
        $before->availableTownTileIds = [TownTile::Coins->value];
        $before->board->hexes = [
            $this->buildingHex('a', 1, ['b']),
            $this->buildingHex('b', 1, ['a', 'c']),
            $this->buildingHex('c', 1, ['b', 'exit']),
            $this->emptyHex('exit', ['c'], TerrainType::Mountain),
        ];
        foreach (array_slice($before->board->hexes, 0, 3) as $hex) {
            $hex->building->type = BuildingType::Guild;
        }
        $after = $before->deepCopy();
        $after->players[0]->availableAnnexes = 0;
        $after->board->hexes[0]->building->hasAnnex = true;
        $blockedBefore = $before->deepCopy();
        array_pop($blockedBefore->board->hexes);
        $blockedBefore->board->hexes[2]->adjacentHexIds = ['b'];
        $blockedAfter = $after->deepCopy();
        array_pop($blockedAfter->board->hexes);
        $blockedAfter->board->hexes[2]->adjacentHexIds = ['b'];
        $evaluator = app(BoardPositionProgressEvaluator::class);

        $earlyDelta = $evaluator->execute($before, $after, 1)
            - $evaluator->execute($blockedBefore, $blockedAfter, 1);
        $this->assertSame(-200, $earlyDelta);

        $before->round->scoringTileId = RoundScoringTile::TownEngineering->value;
        $after->round->scoringTileId = RoundScoringTile::TownEngineering->value;
        $blockedBefore->round->scoringTileId = RoundScoringTile::TownEngineering->value;
        $blockedAfter->round->scoringTileId = RoundScoringTile::TownEngineering->value;
        $townScoringDelta = $evaluator->execute($before, $after, 1)
            - $evaluator->execute($blockedBefore, $blockedAfter, 1);
        $this->assertGreaterThan($earlyDelta, $townScoringDelta);
    }

    public function test_terraforming_only_extending_an_old_town_is_penalized_before_the_final_round(): void
    {
        $before = $this->state();
        $before->round->number = 5;
        $before->players[0]->resources = new PlayerResourcesData(tools: 4, coins: 4);
        $before->board->hexes = [
            $this->buildingHex('town', 1, ['target']),
            $this->emptyHex('target', ['town'], TerrainType::Mountain),
        ];
        $before->board->hexes[0]->townId = 'existing-town';
        $after = $before->deepCopy();
        $after->board->hexes[1]->terrain = TerrainType::Forest;
        $evaluator = app(BoardPositionProgressEvaluator::class);

        $this->assertLessThan(0, $evaluator->execute($before, $after, 1));
        $before->round->number = 6;
        $after->round->number = 6;
        $this->assertGreaterThanOrEqual(0, $evaluator->execute($before, $after, 1));

        $before->round->number = 5;
        $after->round->number = 5;
        $before->board->hexes[] = $this->buildingHex('separate-group', 1, ['target']);
        $after->board->hexes[] = $this->buildingHex('separate-group', 1, ['target']);
        $before->board->hexes[1]->adjacentHexIds[] = 'separate-group';
        $after->board->hexes[1]->adjacentHexIds[] = 'separate-group';
        $this->assertGreaterThanOrEqual(0, $evaluator->execute($before, $after, 1));
    }

    public function test_the_first_of_two_needed_annexes_keeps_its_unfinished_town_progress_value(): void
    {
        $before = $this->state();
        $before->availableTownTileIds = [TownTile::Coins->value];
        $before->round->number = 3;
        $before->players[0]->palaceId = PalaceAbility::Palace08->value;
        $before->players[0]->availableAnnexes = 2;
        $before->board->hexes = [
            $this->buildingHex('a', 1, ['b']),
            $this->buildingHex('b', 1, ['a']),
        ];
        foreach ($before->board->hexes as $hex) {
            $hex->building->type = BuildingType::Guild;
        }
        $after = $before->deepCopy();
        $after->players[0]->availableAnnexes = 1;
        $after->board->hexes[0]->building->hasAnnex = true;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(-95, $evaluator->execute($after, 1) - $evaluator->execute($before, 1)
            + app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
        $this->assertNull($after->board->hexes[0]->townId);

        $positionEvaluator = app(BoardPositionProgressEvaluator::class);
        $before->round->number = 4;
        $after->round->number = 4;
        $fourthRoundScore = $positionEvaluator->execute($before, $after, 1);
        $before->round->scoringTileId = RoundScoringTile::TownEngineering->value;
        $after->round->scoringTileId = RoundScoringTile::TownEngineering->value;
        $this->assertSame($fourthRoundScore + 120, $positionEvaluator->execute($before, $after, 1));
        $before->round->scoringTileId = null;
        $after->round->scoringTileId = null;
        $before->round->number = 5;
        $after->round->number = 5;
        $fifthRoundScore = $positionEvaluator->execute($before, $after, 1);
        $before->round->scoringTileId = RoundScoringTile::TownEngineering->value;
        $after->round->scoringTileId = RoundScoringTile::TownEngineering->value;
        $this->assertSame($fifthRoundScore, $positionEvaluator->execute($before, $after, 1));
    }

    public function test_founding_a_town_preserves_the_value_of_the_annex_needed_to_found_it(): void
    {
        $before = $this->state();
        $before->availableTownTileIds = [TownTile::Coins->value];
        $before->round->number = 3;
        $before->board->hexes = [
            $this->buildingHex('a', 1, ['b']),
            $this->buildingHex('b', 1, ['a', 'c']),
            $this->buildingHex('c', 1, ['b']),
        ];
        foreach ($before->board->hexes as $hex) {
            $hex->building->type = BuildingType::Guild;
        }
        $before->board->hexes[0]->building->hasAnnex = true;
        $after = $before->deepCopy();
        foreach ($after->board->hexes as $hex) {
            $hex->townId = 'new-town';
        }
        $evaluator = app(BoardPositionProgressEvaluator::class);

        $this->assertSame(0, $evaluator->execute($before, $after, 1));
        $extraAnnex = $after->deepCopy();
        $extraAnnex->board->hexes[1]->building->hasAnnex = true;
        $this->assertSame(-120, $evaluator->execute($after, $extraAnnex, 1));
    }

    public function test_a_blocked_two_building_group_has_no_town_upgrade_bonus_without_annexes(): void
    {
        $before = $this->state();
        $before->board->hexes = [
            $this->buildingHex('a', 1, ['b']),
            $this->buildingHex('b', 1, ['a']),
        ];
        $after = $before->deepCopy();
        $after->board->hexes[0]->building->type = BuildingType::Guild;

        $this->assertSame(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_one_annex_is_not_forecast_as_completing_two_separate_towns(): void
    {
        $before = $this->state();
        $before->round->number = 4;
        $before->availableTownTileIds = [TownTile::Coins->value, TownTile::Tools->value];
        $before->board->hexes = [];
        foreach (['first', 'second'] as $prefix) {
            foreach (['a' => ['b'], 'b' => ['a', 'c'], 'c' => ['b']] as $suffix => $neighbors) {
                $hex = $this->buildingHex(
                    $prefix.'-'.$suffix,
                    1,
                    array_map(static fn (string $neighbor): string => $prefix.'-'.$neighbor, $neighbors),
                );
                $hex->building->type = $suffix === 'c' ? BuildingType::School : BuildingType::Guild;
                $before->board->hexes[] = $hex;
            }
        }
        $after = $before->deepCopy();
        $after->players[0]->availableAnnexes = 1;

        $this->assertSame(336, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_annexes_can_complete_the_town_size_even_when_building_power_is_already_sufficient(): void
    {
        $before = $this->state();
        $before->round->number = 4;
        $before->availableTownTileIds = [TownTile::Coins->value];
        $before->board->hexes = [
            $this->buildingHex('palace', 1, ['school']),
            $this->buildingHex('school', 1, ['palace', 'guild']),
            $this->buildingHex('guild', 1, ['school']),
        ];
        $before->board->hexes[0]->building->type = BuildingType::Palace;
        $before->board->hexes[1]->building->type = BuildingType::School;
        $before->board->hexes[2]->building->type = BuildingType::Guild;
        $after = $before->deepCopy();
        $after->players[0]->availableAnnexes = 1;

        $this->assertSame(264, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_upgrading_before_the_next_rounds_matching_scoring_has_an_opportunity_cost(): void
    {
        $before = $this->state();
        $before->round->number = 1;
        $before->round->scoringTileId = RoundScoringTile::PalaceUniversityMedicine->value;
        $before->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'upgrade-timing');
        $before->setupPool->roundScoringTiles[1] = RoundScoringTile::GuildMedicine;
        $before->board->hexes = [$this->buildingHex('home', 1, [])];
        $after = $before->deepCopy();
        $after->board->hexes[0]->building->type = BuildingType::Guild;
        $evaluator = app(RoundScoringProgressEvaluator::class);

        $this->assertSame(-3, $evaluator->execute($before, $after, 1));
        $before->round->scoringTileId = RoundScoringTile::GuildLaw->value;
        $this->assertSame(3, $evaluator->execute($before, $after, 1));
        $before->round->number = 6;
        $before->round->scoringTileId = RoundScoringTile::WorkshopLaw->value;
        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    public function test_building_power_has_no_charging_value_without_neighboring_opponents(): void
    {
        $state = $this->state();
        $state->round->number = 2;
        $state->board->hexes = [$this->buildingHex('home', 1, ['opponent'])];
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(0, $evaluator->evaluateWithBreakdown($state, 1)->buildings);
        $state->board->hexes[] = $this->buildingHex('opponent', 2, ['home']);
        $this->assertSame(40, $evaluator->evaluateWithBreakdown($state, 1)->buildings);
    }

    public function test_excess_coins_are_valued_as_final_resources_instead_of_an_unlimited_building_budget(): void
    {
        $state = $this->state();
        $state->round->number = 2;
        $state->players[0]->resources->coins = 20;
        $evaluator = app(GameStateEvaluator::class);
        $before = $evaluator->evaluateWithBreakdown($state, 1)->resources;

        $state->players[0]->resources->coins++;

        $this->assertSame(2, $evaluator->evaluateWithBreakdown($state, 1)->resources - $before);
    }

    public function test_coins_for_a_near_term_building_keep_their_full_economic_value(): void
    {
        $state = $this->state();
        $state->round->number = 2;
        $state->players[0]->resources->coins = 4;
        $evaluator = app(GameStateEvaluator::class);
        $before = $evaluator->evaluateWithBreakdown($state, 1)->resources;

        $state->players[0]->resources->coins++;

        $this->assertSame(10, $evaluator->evaluateWithBreakdown($state, 1)->resources - $before);
    }

    public function test_a_one_ply_search_prefers_the_round_bonus_spade_to_paid_terraforming(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->roundBonus = RoundBonus::Spade;
        $state->players[0]->resources = new PlayerResourcesData(coins: 15, tools: 5);
        $state->board->hexes = [
            $this->buildingHex('home', 1, ['target']),
            $this->emptyHex('target', ['home'], TerrainType::Mountain),
        ];
        $ranker = app(GameActionRanker::class);
        $ranked = $ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $freeSpade = collect($ranked)->first(fn ($action): bool => $action->option->type() === GameActionOptionType::UseRoundBonusAction);
        $paidSpade = collect($ranked)->first(fn ($action): bool => $action->option->type() === GameActionOptionType::PaidTerraforming);
        $this->assertNotNull($freeSpade);
        $this->assertNotNull($paidSpade);
        $this->assertGreaterThan($paidSpade->score, $freeSpade->score);
        $this->assertGreaterThan(0, $freeSpade->scoreBreakdown->searchAdjustment);
        $this->assertSame(PendingInteractionType::SpendSpades, $freeSpade->simulation->state->pendingInteraction?->type);
        $this->assertSame(5, $freeSpade->simulation->state->players[0]->resources->tools);
        $this->assertNull($state->board->hexes[1]->building);
        $this->assertSame([], $state->players[0]->usedSpecialActionIds);

        $state->players[0]->usedSpecialActionIds = [RoundBonus::Spade->value];
        $state->players[0]->resources->power = new PowerBowlsStateData(bowlThree: 4);
        $state->round->usedSharedActionIds = [PowerAction::GainScholar->value, PowerAction::GainTools->value, PowerAction::GainCoins->value];
        $ranked = $ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $powerSpade = collect($ranked)->first(fn ($action): bool => $action->option instanceof PowerActionOptionData
            && $action->option->action === PowerAction::TerraformOneSpade);
        $paidSpade = collect($ranked)->first(fn ($action): bool => $action->option->type() === GameActionOptionType::PaidTerraforming);
        $this->assertNotNull($powerSpade);
        $this->assertNotNull($paidSpade);
        $this->assertGreaterThan($paidSpade->score, $powerSpade->score);
        $this->assertGreaterThan(0, $powerSpade->scoreBreakdown->searchAdjustment);
    }

    public function test_an_incomplete_search_iteration_does_not_mix_scores_from_different_depths(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 15,
            tools: 5,
            power: new PowerBowlsStateData(bowlTwo: 8, bowlThree: 1),
        );
        $state->players[0]->unassignedSpades = 1;
        $state->board->hexes = [
            $this->buildingHex('home', 1, ['target']),
            $this->emptyHex('target', ['home'], TerrainType::Mountain),
        ];
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            1,
            ['target'],
            ['phase' => 'actions', 'remainingSpades' => 1, 'targetTerrain' => 'forest'],
        );
        $ranker = app(GameActionRanker::class);
        $ranked = $ranker->execute($state, 1, depth: 2, maxNodes: 1);

        $this->assertTrue($ranker->lastBudgetExhausted());
        $this->assertNotEmpty($ranked);
        foreach ($ranked as $action) {
            $this->assertSame(0, $action->scoreBreakdown->searchAdjustment);
            $this->assertSame($action->scoreBreakdown->total(), $action->score);
        }
        $this->assertNull($state->board->hexes[1]->building);
        $this->assertSame(TerrainType::Mountain, $state->board->hexes[1]->terrain);

        $ranked = $ranker->execute($state, 1, depth: 2, maxNodes: 200);
        $this->assertNotSame(GameActionOptionType::ExchangeResources, $ranked[0]->option->type());
        $this->assertNotSame(GameActionOptionType::SacrificePower, $ranked[0]->option->type());
        $this->assertSame('target', $ranked[0]->option->hexId);
        $this->assertSame(PendingInteractionType::BuildWorkshopAfterTerraforming, $ranked[0]->simulation->state->pendingInteraction?->type);
    }

    public function test_it_preserves_power_when_coins_do_not_unlock_construction(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 15,
            tools: 5,
            power: new PowerBowlsStateData(bowlTwo: 8, bowlThree: 1),
        );
        $state->board->hexes = [
            $this->buildingHex('home', 1, ['target']),
            $this->emptyHex('target', ['home']),
        ];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);
        $exchange = collect($ranked)->first(static fn ($action): bool => $action->option->type() === GameActionOptionType::ExchangeResources
            && $action->option->exchange === \App\Domain\GameEngine\Economy\Enums\ResourceExchange::PowerToCoin);

        $this->assertNotNull($exchange);
        $this->assertSame(GameActionOptionType::BuildWorkshop, $ranked[0]->option->type());
        $this->assertGreaterThan($exchange->score, $ranked[0]->score);
        $this->assertLessThan(
            app(GameStateEvaluator::class)->execute($state, 1),
            app(GameStateEvaluator::class)->execute($exchange->simulation->state, 1),
        );
        $this->assertSame(15, $state->players[0]->resources->coins);
        $this->assertSame(1, $state->players[0]->resources->power->bowlThree);
        $sacrifice = collect($ranked)->first(static fn ($action): bool => $action->option->type() === GameActionOptionType::SacrificePower);
        $this->assertNotNull($sacrifice);
        $this->assertGreaterThan($sacrifice->score, $ranked[0]->score);
        $this->assertLessThan(
            app(GameStateEvaluator::class)->execute($state, 1),
            app(GameStateEvaluator::class)->execute($sacrifice->simulation->state, 1),
        );
        $this->assertSame(8, $state->players[0]->resources->power->bowlTwo);

        $state->board->hexes[1]->terrain = TerrainType::Mountain;
        $state->players[0]->unassignedSpades = 1;
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            1,
            ['target'],
            ['phase' => 'actions', 'remainingSpades' => 1, 'targetTerrain' => 'forest'],
        );
        $pendingRanked = app(GameActionRanker::class)->execute($state, 1, depth: 1);

        $this->assertNotSame(GameActionOptionType::ExchangeResources, $pendingRanked[0]->option->type());
        $this->assertNotSame(GameActionOptionType::SacrificePower, $pendingRanked[0]->option->type());
        $this->assertSame('target', $pendingRanked[0]->option->hexId);
    }

    public function test_a_remaining_spade_prepares_an_unfinished_group_instead_of_extending_a_completed_town(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(coins: 4, tools: 7);
        $state->players[0]->unassignedSpades = 1;
        $state->board->hexes = [
            $this->buildingHex('town', 1, ['wasted']),
            $this->emptyHex('wasted', ['town'], TerrainType::Plains),
            $this->buildingHex('separate', 1, ['useful']),
            $this->emptyHex('useful', ['separate'], TerrainType::Plains),
        ];
        $state->board->hexes[0]->townId = 'existing';
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            1,
            ['wasted', 'useful'],
            ['phase' => 'actions', 'remainingSpades' => 1, 'targetTerrain' => 'forest'],
        );

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $this->assertSame('useful', $ranked[0]->option->hexId);
        $this->assertSame(TerrainType::Swamp, $ranked[0]->simulation->state->board->hexes[3]->terrain);

        $evaluator = app(BoardPositionProgressEvaluator::class);
        $partial = $state->deepCopy();
        $partial->board->hexes[3]->terrain = TerrainType::Swamp;
        $partial->players[0]->unassignedSpades = 0;
        $funded = $evaluator->execute($state, $partial, 1);
        $partial->players[0]->resources->tools = 0;
        $this->assertGreaterThan($evaluator->execute($state, $partial, 1), $funded);
        $this->assertSame(TerrainType::Plains, $state->board->hexes[3]->terrain);
    }

    public function test_completed_terraforming_is_more_valuable_than_partial_terraforming(): void
    {
        $state = $this->state();
        $state->board->hexes = [
            $this->buildingHex('home', 1, ['complete', 'partial']),
            $this->emptyHex('complete', ['home'], TerrainType::Mountain),
            $this->emptyHex('partial', ['home'], TerrainType::Wasteland),
        ];
        $completed = $state->deepCopy();
        $completed->board->hexes[1]->terrain = TerrainType::Forest;
        $partial = $state->deepCopy();
        $partial->board->hexes[2]->terrain = TerrainType::Mountain;
        $evaluator = app(BoardPositionProgressEvaluator::class);

        $this->assertGreaterThan(0, $evaluator->execute($state, $completed, 1));
        $this->assertGreaterThan(
            $evaluator->execute($state, $partial, 1),
            $evaluator->execute($state, $completed, 1),
        );
    }

    public function test_a_spade_prefers_completed_terraforming_and_offers_construction(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(coins: 16, tools: 5);
        $state->players[0]->faction = Faction::Monks;
        $state->players[0]->unassignedSpades = 1;
        $state->board->hexes = [
            $this->buildingHex('home', 1, ['partial', 'complete']),
            $this->emptyHex('partial', ['home'], TerrainType::Wasteland),
            $this->emptyHex('complete', ['home'], TerrainType::Mountain),
        ];
        $state->board->hexes[0]->building->type = BuildingType::University;
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            1,
            ['partial', 'complete'],
            ['phase' => 'actions', 'remainingSpades' => 1, 'targetTerrain' => 'forest'],
        );
        $ranker = app(GameActionRanker::class);
        $ranked = $ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame('complete', $ranked[0]->option->hexId);
        $this->assertSame(PendingInteractionType::BuildWorkshopAfterTerraforming, $ranked[0]->simulation->state->pendingInteraction?->type);
        $continuations = $ranker->execute($ranked[0]->simulation->state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $construction = collect($continuations)->first(static fn ($action): bool => $action->option->build);
        $this->assertNotNull($construction);
        $this->assertSame('complete', $construction->option->hexId);
        $this->assertSame(BuildingType::Workshop, $construction->simulation->state->board->hexes[2]->building?->type);
        $this->assertSame(TerrainType::Mountain, $state->board->hexes[2]->terrain);
        $this->assertNull($state->board->hexes[2]->building);
    }

    public function test_it_converts_a_surplus_tool_to_fund_reachable_construction_instead_of_passing(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->round->number = 5;
        $state->round->scoringTileId = RoundScoringTile::WorkshopLaw->value;
        $state->players[0]->resources = new PlayerResourcesData(coins: 0, tools: 9);
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['target']),
            $this->emptyHex('target', ['a']),
        ];
        $state->availableTownTileIds = [];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);

        $this->assertSame(GameActionOptionType::ExchangeResources, $ranked[0]->option->type());
        $this->assertSame(\App\Domain\GameEngine\Economy\Enums\ResourceExchange::ToolToCoin, $ranked[0]->option->exchange);
        $this->assertGreaterThan(0, $ranked[0]->scoreBreakdown->economicNeeds);
        $this->assertSame(0, $state->players[0]->resources->coins);
        $this->assertSame(9, $state->players[0]->resources->tools);
    }

    public function test_a_one_ply_search_prefers_an_upgrade_that_completes_a_town(): void
    {
        $state = $this->townUpgradeState();
        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame(72, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertSame(PendingInteractionType::ChooseTown, $ranked[0]->simulation->state->pendingInteraction?->type);
        $this->assertSame([], $ranked[0]->simulation->state->players[0]->townTileIds);
        $this->assertSame([], $state->players[0]->townTileIds);
        $this->assertNull($state->board->hexes[0]->townId);
        $this->assertSame($ranked[0]->score, $ranked[0]->scoreBreakdown->total());
    }

    public function test_a_town_reward_is_not_projected_when_the_town_is_ineligible(): void
    {
        $state = $this->townUpgradeState();
        array_pop($state->board->hexes);
        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $upgrade = collect($ranked)->first(
            static fn ($action): bool => $action->option->type() === GameActionOptionType::UpgradeBuilding,
        );

        $this->assertNotNull($upgrade);
        $this->assertSame(0, $upgrade->scoreBreakdown->searchAdjustment);
        $this->assertSame([], $state->players[0]->townTileIds);
    }

    public function test_a_town_reward_is_not_projected_when_no_tiles_remain(): void
    {
        $state = $this->townUpgradeState();
        $state->availableTownTileIds = [];
        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $upgrade = collect($ranked)->first(
            static fn ($action): bool => $action->option->type() === GameActionOptionType::UpgradeBuilding,
        );

        $this->assertNotNull($upgrade);
        $this->assertSame(0, $upgrade->scoreBreakdown->searchAdjustment);
    }

    public function test_a_one_ply_search_includes_a_town_after_a_university_competency(): void
    {
        $state = $this->townUpgradeState();
        $state->board->hexes[0]->building->type = BuildingType::School;
        $state->players[0]->resources = new PlayerResourcesData(coins: 8, tools: 5);
        $state->availableCompetencyIds = [Competency::Competency04->value];
        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame(BuildingType::University, $ranked[0]->option->target);
        $this->assertSame(208, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertSame([], $ranked[0]->simulation->state->players[0]->townTileIds);
        $this->assertSame([], $state->players[0]->competencyIds);
    }

    public function test_projecting_town_rewards_respects_the_search_node_budget(): void
    {
        $state = $this->townUpgradeState();
        $ranker = app(GameActionRanker::class);
        $ranked = $ranker->execute($state, 1, depth: 1, maxNodes: 1, auxiliaryActionsRemaining: 0);

        $this->assertNotEmpty($ranked);
        $this->assertSame(1, $ranker->lastVisitedNodes());
        $this->assertTrue($ranker->lastBudgetExhausted());
        foreach ($ranked as $action) {
            $this->assertSame($action->score, $action->scoreBreakdown->total());
        }
        $this->assertSame([], $state->players[0]->townTileIds);
    }

    public function test_a_one_ply_search_values_the_competency_awarded_by_a_school(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->round->number = 6;
        $state->players[0]->resources = new PlayerResourcesData(coins: 5, tools: 3);
        $state->availableCompetencyIds = [Competency::Competency01->value, Competency::Competency04->value];
        $state->availablePalaceIds = [];
        $state->board->hexes = [$this->buildingHex('guild', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::Guild;

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame(BuildingType::School, $ranked[0]->option->target);
        $this->assertSame(136, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertSame($ranked[0]->score, $ranked[0]->scoreBreakdown->total());
        $this->assertSame(PendingInteractionType::ChooseCompetency, $ranked[0]->simulation->state->pendingInteraction->type);
        $this->assertSame([], $ranked[0]->simulation->state->players[0]->competencyIds);
        $this->assertSame([], $state->players[0]->competencyIds);
        $this->assertSame(BuildingType::Guild, $state->board->hexes[0]->building->type);
    }

    public function test_competency_choice_values_repeated_town_pass_rewards(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 2;
        $state->players[0]->townTileIds = [TownTile::Tools->value, TownTile::Coins->value];
        $state->availableCompetencyIds = [Competency::Competency04->value, Competency::Competency08->value];
        $state->pendingInteraction = new PendingInteractionData(
            type: PendingInteractionType::ChooseCompetency,
            playerId: 1,
            optionIds: $state->availableCompetencyIds,
            context: ['reason' => 'building'],
        );

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(Competency::Competency08, $ranked[0]->option->competency);
        $this->assertSame([], $state->players[0]->competencyIds);
    }

    public function test_competency_choice_prefers_annexes_that_enable_a_separate_town(): void
    {
        $state = $this->state();
        $state->availableTownTileIds = [TownTile::Coins->value];
        $state->turnOrder = [1];
        $state->round->number = 4;
        $state->availableCompetencyIds = [Competency::Competency02->value, Competency::Competency06->value];
        $state->board->hexes = [
            $this->buildingHex('palace', 1, ['school']),
            $this->buildingHex('school', 1, ['palace', 'guild']),
            $this->buildingHex('guild', 1, ['school']),
        ];
        $state->board->hexes[0]->building->type = BuildingType::Palace;
        $state->board->hexes[1]->building->type = BuildingType::School;
        $state->board->hexes[2]->building->type = BuildingType::Guild;
        $state->pendingInteraction = new PendingInteractionData(
            type: PendingInteractionType::ChooseCompetency,
            playerId: 1,
            optionIds: $state->availableCompetencyIds,
            context: ['reason' => 'building'],
        );

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(Competency::Competency06, $ranked[0]->option->competency);
        $this->assertSame(2, $ranked[0]->simulation->state->players[0]->availableAnnexes);
        $this->assertSame(0, $state->players[0]->availableAnnexes);
    }

    public function test_annex_options_exclude_completed_towns_and_unfunded_isolated_buildings(): void
    {
        $state = $this->state();
        $state->round->number = 6;
        $state->turnOrder = [1];
        $state->players[0]->availableAnnexes = 2;
        $state->availableTownTileIds = [TownTile::Coins->value];
        $state->board->hexes = [
            $this->buildingHex('palace', 1, []),
            $this->buildingHex('guild', 1, []),
        ];
        $state->board->hexes[0]->building->type = BuildingType::Palace;
        $state->board->hexes[0]->townId = 'existing-town';
        $state->board->hexes[1]->building->type = BuildingType::Guild;

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertNotEmpty($ranked);
        foreach ($ranked as $candidate) {
            $this->assertNotSame(GameActionOptionType::PlaceAnnex, $candidate->option->type());
        }
        $this->assertSame(2, $state->players[0]->availableAnnexes);
    }

    public function test_competency_choice_excludes_annexes_without_a_feasible_town(): void
    {
        $state = $this->state();
        $state->round->number = 6;
        $state->turnOrder = [1];
        $state->availableTownTileIds = [TownTile::Coins->value];
        $state->board->hexes = [$this->buildingHex('school', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::School;
        $state->board->hexes[0]->townId = 'existing-town';
        $state->availableCompetencyIds = [Competency::Competency04->value, Competency::Competency06->value];
        $state->pendingInteraction = new PendingInteractionData(
            type: PendingInteractionType::ChooseCompetency,
            playerId: 1,
            optionIds: $state->availableCompetencyIds,
            context: ['reason' => 'building'],
        );

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertCount(1, $ranked);
        $this->assertSame(Competency::Competency04, $ranked[0]->option->competency);

        $state->availableCompetencyIds = [Competency::Competency06->value];
        $state->pendingInteraction->optionIds = $state->availableCompetencyIds;
        $forced = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertCount(1, $forced);
        $this->assertSame(Competency::Competency06, $forced[0]->option->competency);
    }

    public function test_completed_reward_forecasts_survive_exhaustion_of_deeper_search(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 6;
        $state->players[0]->resources = new PlayerResourcesData(coins: 5, tools: 3, books: new BookSupplyData(law: 1));
        $state->availableCompetencyIds = [Competency::Competency04->value];
        $state->availablePalaceIds = [];
        $state->board->hexes = [$this->buildingHex('guild', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::Guild;
        $ranker = app(GameActionRanker::class);

        $ranked = $ranker->execute($state, 1, depth: 2, maxNodes: 2);

        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame(BuildingType::School, $ranked[0]->option->target);
        $this->assertSame(136, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertTrue($ranker->lastBudgetExhausted());
        $this->assertSame([], $state->players[0]->competencyIds);
    }

    public function test_completed_reward_forecasts_survive_exhaustion_of_the_initial_reward_pass(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 6;
        $state->players[0]->resources = new PlayerResourcesData(coins: 5, tools: 3);
        $state->availableCompetencyIds = [Competency::Competency04->value];
        $state->availablePalaceIds = [];
        $state->board->hexes = [
            $this->buildingHex('first', 1, []),
            $this->buildingHex('second', 1, []),
            $this->buildingHex('third', 1, []),
        ];
        foreach ($state->board->hexes as $hex) {
            $hex->building->type = BuildingType::Guild;
        }
        $ranker = app(GameActionRanker::class);

        $ranked = $ranker->execute($state, 1, depth: 1, maxNodes: 2, auxiliaryActionsRemaining: 0);

        $this->assertTrue($ranker->lastBudgetExhausted());
        $this->assertSame(2, $ranker->lastVisitedNodes());
        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame('first', $ranked[0]->option->hexId);
        $this->assertSame(136, $ranked[0]->scoreBreakdown->searchAdjustment);
        foreach ($ranked as $action) {
            $this->assertSame($action->scoreBreakdown->total(), $action->score);
        }
        $this->assertSame([], $state->players[0]->competencyIds);
    }

    public function test_a_one_ply_search_values_the_income_and_books_awarded_by_a_palace(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->round->number = 1;
        $state->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 4, power: new PowerBowlsStateData(bowlOne: 12));
        $state->availableCompetencyIds = [];
        $state->availablePalaceIds = [PalaceAbility::Palace01->value, PalaceAbility::Palace10->value];
        $state->board->hexes = [$this->buildingHex('guild', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::Guild;

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame(BuildingType::Palace, $ranked[0]->option->target);
        $this->assertSame(371, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertSame($ranked[0]->score, $ranked[0]->scoreBreakdown->total());
        $this->assertSame(PendingInteractionType::ChoosePalace, $ranked[0]->simulation->state->pendingInteraction->type);
        $this->assertNull($ranked[0]->simulation->state->players[0]->palaceId);
        $this->assertNull($state->players[0]->palaceId);
        $this->assertSame(BuildingType::Guild, $state->board->hexes[0]->building->type);
    }

    public function test_iterative_search_reuses_a_completed_palace_reward_forecast(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 1;
        $state->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 4);
        $state->availableCompetencyIds = [];
        $state->availablePalaceIds = [PalaceAbility::Palace10->value];
        $state->board->hexes = [$this->buildingHex('guild', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::Guild;
        $ranker = app(GameActionRanker::class);

        $ranked = $ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertNotEmpty($ranked);
        $this->assertSame(1, $ranker->lastSearchTimings()->simulationsByAction[GameActionOptionType::ChoosePalace->value]->calls);
        $this->assertSame(2, $ranker->lastVisitedNodes());
        $this->assertNull($state->players[0]->palaceId);
    }

    public function test_palace_rewards_are_forecast_after_an_opponent_power_offer(): void
    {
        $state = $this->state();
        $opponent = $state->deepCopy()->players[0];
        $opponent->playerId = 2;
        $opponent->userId = 20;
        $opponent->resources->power = new PowerBowlsStateData(bowlOne: 12);
        $state->players[] = $opponent;
        $state->turnOrder = [1, 2];
        $state->round->number = 1;
        $state->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 4);
        $state->availableCompetencyIds = [];
        $state->availablePalaceIds = [PalaceAbility::Palace10->value];
        $state->board->hexes = [
            $this->buildingHex('guild', 1, ['opponent']),
            $this->buildingHex('opponent', 2, ['guild']),
        ];
        $state->board->hexes[0]->building->type = BuildingType::Guild;

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $palace = collect($ranked)->first(static fn ($action): bool => $action->option->type() === GameActionOptionType::UpgradeBuilding
            && $action->option->target === BuildingType::Palace);

        $this->assertNotNull($palace);
        $this->assertSame(BuildingType::Palace, $palace->option->target);
        $this->assertSame(PendingInteractionType::PowerOffer, $palace->simulation->state->pendingInteraction?->type);
        $this->assertGreaterThan(0, $palace->scoreBreakdown->searchAdjustment);
        $this->assertNull($state->players[0]->palaceId);
        $this->assertSame(12, $state->players[1]->resources->power->bowlOne);
    }

    public function test_state_evaluation_values_a_palace_ability_beyond_its_immediate_income(): void
    {
        $withoutPalace = $this->state();
        $withoutPalace->round->number = 6;
        $withPalace = $withoutPalace->deepCopy();
        $withPalace->players[0]->palaceId = PalaceAbility::Palace14->value;

        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(
            60,
            $evaluator->execute($withPalace, 1) - $evaluator->execute($withoutPalace, 1),
        );
    }

    public function test_palace_spade_forecast_follows_the_reward_order_choice(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 6;
        $state->players[0]->resources = new PlayerResourcesData(coins: 8, tools: 5);
        $state->availableCompetencyIds = [];
        $state->availablePalaceIds = [PalaceAbility::Palace15->value];
        $state->board->hexes = [$this->buildingHex('guild', 1, ['target'])];
        $state->board->hexes[0]->building->type = BuildingType::Guild;
        $ranker = app(GameActionRanker::class);
        $withoutTarget = collect($ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0))
            ->first(static fn ($action): bool => $action->option->type() === GameActionOptionType::UpgradeBuilding
                && $action->option->target === BuildingType::Palace);
        $state->board->hexes[] = $this->emptyHex('target', ['guild'], TerrainType::Mountain);
        $withTarget = collect($ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0))
            ->first(static fn ($action): bool => $action->option->type() === GameActionOptionType::UpgradeBuilding
                && $action->option->target === BuildingType::Palace);

        $this->assertNotNull($withoutTarget);
        $this->assertNotNull($withTarget);
        $this->assertGreaterThan($withoutTarget->scoreBreakdown->searchAdjustment, $withTarget->scoreBreakdown->searchAdjustment);
        $this->assertNull($state->board->hexes[1]->building);
        $this->assertSame(TerrainType::Mountain, $state->board->hexes[1]->terrain);
        $this->assertSame([], $state->pendingInteractionQueue);
    }

    public function test_palace_forecast_includes_its_free_guild_placement(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 6;
        $state->players[0]->roundBonus = RoundBonus::BuildGuild;
        $state->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 4);
        $state->availableCompetencyIds = [];
        $state->availablePalaceIds = [PalaceAbility::Palace16->value];
        $state->board->hexes = [$this->buildingHex('guild', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::Guild;
        $ranker = app(GameActionRanker::class);
        $withoutTarget = collect($ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0))
            ->first(static fn ($action): bool => $action->option->type() === GameActionOptionType::UpgradeBuilding);
        $state->board->hexes[] = $this->emptyHex('free-guild', []);
        $withTarget = collect($ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0))
            ->first(static fn ($action): bool => $action->option->type() === GameActionOptionType::UpgradeBuilding);

        $this->assertNotNull($withoutTarget);
        $this->assertNotNull($withTarget);
        $this->assertGreaterThan($withoutTarget->score, $withTarget->score);
        $this->assertGreaterThan(0, $withTarget->scoreBreakdown->searchAdjustment);
        $this->assertNull($state->board->hexes[1]->building);
    }

    public function test_the_free_guild_forecast_values_its_position_before_selecting_a_palace(): void
    {
        $far = $this->state();
        $far->turnOrder = [1];
        $far->round->number = 1;
        $far->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 4);
        $far->availableCompetencyIds = [];
        $far->availablePalaceIds = [PalaceAbility::Palace16->value];
        $far->board->hexes = [$this->buildingHex('guild', 1, []), $this->emptyHex('target', [])];
        $far->board->hexes[0]->building->type = BuildingType::Guild;
        $near = $far->deepCopy();
        $near->board->hexes[0]->adjacentHexIds = ['target'];
        $near->board->hexes[1]->adjacentHexIds = ['guild'];
        $ranker = app(GameActionRanker::class);
        $farUpgrade = collect($ranker->execute($far, 1, depth: 1, auxiliaryActionsRemaining: 0))->first(
            fn ($action): bool => $action->option->type() === GameActionOptionType::UpgradeBuilding
                && $action->option->target === BuildingType::Palace,
        );
        $nearUpgrade = collect($ranker->execute($near, 1, depth: 1, auxiliaryActionsRemaining: 0))->first(
            fn ($action): bool => $action->option->type() === GameActionOptionType::UpgradeBuilding
                && $action->option->target === BuildingType::Palace,
        );

        $this->assertNotNull($farUpgrade);
        $this->assertNotNull($nearUpgrade);
        $this->assertGreaterThan($farUpgrade->scoreBreakdown->searchAdjustment, $nearUpgrade->scoreBreakdown->searchAdjustment);
        $this->assertNull($near->board->hexes[1]->building);
        $this->assertNull($far->board->hexes[1]->building);
    }

    public function test_state_evaluation_values_books_that_approach_an_available_innovation(): void
    {
        $state = $this->state();
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(3, 'innovation-book-value');
        $state->setupPool->innovations[0] = Innovation::Library;
        $state->availableInventionIds = [Innovation::Library->value];
        $state->players[0]->knowledge = new KnowledgeStateData(banking: 12, law: 12);
        $state->players[0]->resources->coins = 5;
        $state->players[0]->resources->books->banking = 2;
        $state->players[0]->resources->books->law = 1;
        $threeBooks = app(GameStateEvaluator::class)->execute($state, 1);
        $state->players[0]->resources->books->law = 2;
        $fourBooks = app(GameStateEvaluator::class)->execute($state, 1);

        $this->assertSame(38, $fourBooks - $threeBooks);
    }

    public function test_the_first_useful_book_already_has_innovation_readiness_value(): void
    {
        $state = $this->state();
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(3, 'first-useful-innovation-book');
        $state->setupPool->innovations[0] = Innovation::Library;
        $state->availableInventionIds = [Innovation::Library->value];
        $state->players[0]->knowledge = new KnowledgeStateData(banking: 12, law: 12);
        $evaluator = app(GameStateEvaluator::class);
        $before = $evaluator->execute($state, 1);
        $state->players[0]->resources->books->banking = 1;
        $this->assertSame(38, $evaluator->execute($state, 1) - $before);
        $state->availableInventionIds = [];
        $this->assertSame(25, $evaluator->execute($state, 1) - $before);
    }

    public function test_innovation_readiness_stops_after_the_book_set_is_complete(): void
    {
        $state = $this->state();
        $state->setupPool = app(GameSetupPoolFactory::class)->create(3);
        $state->availableInventionIds = array_map(static fn (Innovation $innovation): string => $innovation->value, $state->setupPool->innovations);
        $state->players[0]->resources->books = new BookSupplyData(banking: 5, law: 5, engineering: 5, medicine: 5);
        $evaluator = app(GameStateEvaluator::class);
        $before = $evaluator->execute($state, 1);
        $state->players[0]->resources->books->banking++;
        $this->assertSame(25, $evaluator->execute($state, 1) - $before);

        $state->players[0]->inventionIds = [Innovation::Architecture->value, Innovation::Census->value, Innovation::Science->value];
        $before = $evaluator->execute($state, 1);
        $state->players[0]->resources->books->banking++;
        $this->assertSame(25, $evaluator->execute($state, 1) - $before);
    }

    public function test_high_knowledge_levels_do_not_inflate_the_value_of_a_single_step(): void
    {
        $state = $this->state();
        $state->round->number = 6;
        $state->players[0]->knowledge->banking = 11;
        $evaluator = app(GameStateEvaluator::class);
        $before = $evaluator->execute($state, 1);
        $state->players[0]->knowledge->banking = 12;

        $this->assertSame(0, $evaluator->execute($state, 1) - $before);
    }

    public function test_the_final_round_innovation_is_preferred_to_spending_its_books_on_high_knowledge(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 6;
        $state->round->scoringTileId = RoundScoringTile::WorkshopBanking->value;
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(3, 'library-versus-knowledge');
        $state->setupPool->innovations[0] = Innovation::Library;
        $state->setupPool->bookActions = [BookAction::AdvanceKnowledge];
        $state->availableInventionIds = [Innovation::Library->value];
        $state->players[0]->knowledge = new KnowledgeStateData(banking: 10, law: 4, engineering: 4, medicine: 4);
        $state->players[0]->resources = new PlayerResourcesData(coins: 5, books: new BookSupplyData(banking: 5));
        $state->board->hexes = [$this->buildingHex('workshop', 1, [])];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertInstanceOf(MakeInnovationOptionData::class, $ranked[0]->option);
        $this->assertSame(Innovation::Library, $ranked[0]->option->innovation);
        $this->assertSame(5, $state->players[0]->resources->books->banking);
    }

    public function test_bot_saves_an_almost_complete_book_set_instead_of_advancing_uncontested_knowledge(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 1;
        $state->round->scoringTileId = RoundScoringTile::WorkshopBanking->value;
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(3, 'save-books-for-architecture');
        $state->setupPool->roundScoringTiles = [];
        $state->setupPool->innovations[0] = Innovation::Architecture;
        $state->setupPool->bookActions = [BookAction::AdvanceKnowledge];
        $state->availableInventionIds = [Innovation::Architecture->value];
        $state->players[0]->knowledge = new KnowledgeStateData(banking: 5, law: 5, engineering: 5, medicine: 5);
        $state->players[0]->resources = new PlayerResourcesData(coins: 5, books: new BookSupplyData(banking: 2, law: 2));
        $state->board->hexes = [$this->buildingHex('workshop', 1, [])];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::Pass, $ranked[0]->option->type());
        $this->assertSame(2, $state->players[0]->resources->books->banking);
        $this->assertSame(2, $state->players[0]->resources->books->law);
    }

    public function test_an_acquired_innovation_without_residual_rewards_has_no_automatic_bonus(): void
    {
        $withoutInnovation = $this->state();
        $withInnovation = $withoutInnovation->deepCopy();
        $withInnovation->players[0]->inventionIds = [Innovation::Telecommunication->value];

        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(
            0,
            $evaluator->execute($withInnovation, 1) - $evaluator->execute($withoutInnovation, 1),
        );
    }

    public function test_bot_uses_a_complete_book_set_to_make_an_innovation(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 6;
        $state->round->scoringTileId = RoundScoringTile::WorkshopBanking->value;
        $state->setupPool = app(GameSetupPoolFactory::class)->create(3);
        $state->setupPool->innovations[0] = Innovation::Architecture;
        $state->availableInventionIds = [Innovation::Architecture->value];
        $state->board->hexes = [$this->buildingHex('workshop', 1, [])];
        $state->round->usedBookActionIds = array_map(
            static fn ($action): string => $action->value,
            $state->setupPool->bookActions,
        );
        $cost = app(InnovationPurchaseCostCalculator::class)->cost(
            3,
            0,
            0,
            false,
            false,
        );
        $books = new BookSupplyData(...$cost['requiredBooks']);
        $books->banking += $cost['totalBooks'] - array_sum($cost['requiredBooks']);
        $state->players[0]->resources = new PlayerResourcesData(coins: 5, books: $books);

        $ranked = app(GameActionRanker::class)->execute(
            $state,
            1,
            depth: 1,
            auxiliaryActionsRemaining: 0,
        );

        $this->assertInstanceOf(
            MakeInnovationOptionData::class,
            $ranked[0]->option,
            json_encode(array_map(
                static fn ($action): array => [
                    'type' => $action->option->type()->value,
                    'score' => $action->score,
                    'breakdown' => [
                        'state' => $action->scoreBreakdown->state->total(),
                        'round' => $action->scoreBreakdown->roundScoring,
                        'final' => $action->scoreBreakdown->finalScoring,
                        'board' => $action->scoreBreakdown->boardPosition,
                        'economic' => $action->scoreBreakdown->economicNeeds,
                        'pass' => $action->scoreBreakdown->passPenalty,
                        'search' => $action->scoreBreakdown->searchAdjustment,
                    ],
                ],
                $ranked,
            ), JSON_THROW_ON_ERROR),
        );
    }

    public function test_a_one_ply_search_values_the_competency_awarded_by_a_university(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->round->number = 6;
        $state->players[0]->resources = new PlayerResourcesData(coins: 8, tools: 5);
        $state->availableCompetencyIds = [Competency::Competency04->value];
        $state->board->hexes = [$this->buildingHex('school', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::School;

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame(BuildingType::University, $ranked[0]->option->target);
        $this->assertSame(136, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertSame([], $state->players[0]->competencyIds);
    }

    public function test_an_upgrade_does_not_gain_value_from_an_already_owned_competency(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->round->number = 6;
        $state->players[0]->resources = new PlayerResourcesData(coins: 5, tools: 3);
        $state->players[0]->competencyIds = [Competency::Competency04->value];
        $state->availableCompetencyIds = [Competency::Competency04->value];
        $state->board->hexes = [$this->buildingHex('guild', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::Guild;

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $upgrade = collect($ranked)->first(
            static fn ($action): bool => $action->option->type() === GameActionOptionType::UpgradeBuilding,
        );

        $this->assertNotNull($upgrade);
        $this->assertSame(0, $upgrade->scoreBreakdown->searchAdjustment);
        $this->assertSame([Competency::Competency04->value], $upgrade->simulation->state->players[0]->competencyIds);
        $this->assertSame($state->players[0]->victoryPoints, $upgrade->simulation->state->players[0]->victoryPoints);
    }

    public function test_terraforming_continuation_can_decline_when_construction_is_unaffordable(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->players[0]->resources = new PlayerResourcesData(coins: 0, tools: 3);
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['target']),
            $this->emptyHex('target', ['a'], TerrainType::Mountain),
        ];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $terraforming = collect($ranked)->first(
            static fn ($action): bool => $action->option->type() === GameActionOptionType::PaidTerraforming,
        );

        $this->assertNotNull($terraforming);
        $this->assertSame(0, $terraforming->scoreBreakdown->searchAdjustment);
        $this->assertSame($terraforming->score, $terraforming->scoreBreakdown->total());
        $this->assertSame(TerrainType::Mountain, $state->board->hexes[1]->terrain);
        $this->assertNull($state->board->hexes[1]->building);
    }

    public function test_a_one_ply_search_finishes_terraforming_before_evaluating_it(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->players[0]->resources = new PlayerResourcesData(coins: 2, tools: 4);
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['target']),
            $this->emptyHex('target', ['a'], TerrainType::Mountain),
        ];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $terraforming = collect($ranked)->first(
            static fn ($action): bool => $action->option->type() === GameActionOptionType::PaidTerraforming,
        );

        $this->assertNotNull($terraforming);
        $this->assertGreaterThan(0, $terraforming->scoreBreakdown->searchAdjustment);
        $this->assertSame($terraforming->score, $terraforming->scoreBreakdown->total());
        $this->assertNotNull($terraforming->simulation->state->pendingInteraction);
        $this->assertNull($state->board->hexes[1]->building);
        $this->assertSame(TerrainType::Mountain, $state->board->hexes[1]->terrain);
    }

    public function test_terraforming_horizon_accounts_for_the_strategic_value_of_a_fifth_workshop(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->round->number = 2;
        $state->players[0]->terraformingLevel = 2;
        $state->players[0]->resources = new PlayerResourcesData(coins: 15, tools: 5);
        $state->availableTownTileIds = [];
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['target']),
            $this->buildingHex('b', 1, []),
            $this->buildingHex('c', 1, ['target']),
            $this->buildingHex('d', 1, []),
            $this->emptyHex('target', ['a', 'c'], TerrainType::Mountain),
        ];

        $ranker = app(GameActionRanker::class);
        $ranked = $ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $terraforming = collect($ranked)->first(
            static fn ($action): bool => $action->option->type() === GameActionOptionType::PaidTerraforming,
        );
        $this->assertNotNull($terraforming);
        $continuations = $ranker->execute($terraforming->simulation->state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $this->assertTrue($continuations[0]->option->build);
        $decline = collect($continuations)->first(static fn ($action): bool => ! $action->option->build);
        $this->assertNotNull($decline);
        $this->assertGreaterThan($decline->score, $continuations[0]->score);
        $this->assertNull($state->board->hexes[4]->building);
    }

    public function test_a_narrow_search_considers_construction_unlocked_by_a_conversion(): void
    {
        $state = $this->state();
        $state->round->scoringTileId = RoundScoringTile::WorkshopBanking->value;
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 1,
            tools: 1,
            power: new PowerBowlsStateData(bowlThree: 1),
        );
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['target']),
            $this->emptyHex('target', ['a']),
        ];

        $ranker = app(GameActionRanker::class);
        $narrow = $ranker->execute($state, 1, depth: 1, branchLimit: 1);
        $wide = $ranker->execute($state, 1, depth: 1, branchLimit: 8);

        $this->assertSame(GameActionOptionType::ExchangeResources, $narrow[0]->option->type());
        $this->assertSame($wide[0]->score, $narrow[0]->score);
        $this->assertGreaterThan(0, $narrow[0]->scoreBreakdown->searchAdjustment);
        $this->assertSame($narrow[0]->score, $narrow[0]->scoreBreakdown->total());
        $this->assertSame(1, $state->players[0]->resources->coins);
        $this->assertNull($state->board->hexes[1]->building);
    }

    public function test_it_prefers_shipping_when_it_unlocks_funded_construction(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->players[0]->resources->coins = 6;
        $state->players[0]->resources->tools = 1;
        $state->players[0]->resources->scholars = 1;
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['water']),
            $this->emptyHex('water', ['a', 'b', 'c', 'd'], TerrainType::Water),
            $this->emptyHex('b', ['water']),
            $this->emptyHex('c', ['water']),
            $this->emptyHex('d', ['water']),
        ];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 2);

        $this->assertSame(GameActionOptionType::AdvanceShipping, $ranked[0]->option->type());
    }

    public function test_it_prefers_power_tools_over_coins_when_tools_unlock_an_upgrade(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->players[0]->resources->coins = 50;
        $state->players[0]->resources->tools = 1;
        $state->players[0]->resources->power = new PowerBowlsStateData(bowlThree: 4);
        $state->board->hexes = [$this->buildingHex('a', 1, [])];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);
        $tools = collect($ranked)->first(static fn ($action): bool => $action->option instanceof PowerActionOptionData
            && $action->option->action === PowerAction::GainTools);
        $coins = collect($ranked)->first(static fn ($action): bool => $action->option instanceof PowerActionOptionData
            && $action->option->action === PowerAction::GainCoins);

        $this->assertNotNull($tools);
        $this->assertNull($coins);
    }

    public function test_it_prefers_a_workshop_that_improves_town_cohesion(): void
    {
        $state = $this->state();
        $state->turnOrder = [1, 2];
        $state->players[] = new GamePlayerStateData(
            playerId: 2,
            userId: 20,
            color: PlayerColor::Red,
            faction: Faction::Inventors,
            homeland: TerrainType::Wasteland,
            roundBonus: RoundBonus::Coins,
        );
        $state->players[0]->resources->tools = 1;
        $state->players[0]->resources->coins = 2;
        $state->players[0]->shippingLevel = 1;
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['connected', 'water']),
            $this->emptyHex('connected', ['a']),
            $this->emptyHex('water', ['a', 'remote'], TerrainType::Water),
            $this->emptyHex('remote', ['water']),
        ];

        $rankedActions = app(GameActionRanker::class)->execute($state, 1, depth: 1);

        $this->assertInstanceOf(BuildWorkshopOptionData::class, $rankedActions[0]->option);
        $this->assertSame('connected', $rankedActions[0]->option->hexId);
    }

    public function test_a_fast_bot_finishes_a_town_instead_of_expanding_its_river_network(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 5;
        $state->players[0]->resources = new PlayerResourcesData(tools: 1, coins: 2);
        $state->players[0]->shippingLevel = 1;
        $state->availableTownTileIds = [TownTile::Coins->value];
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['b', 'water']),
            $this->buildingHex('b', 1, ['a', 'c']),
            $this->buildingHex('c', 1, ['b', 'town-target']),
            $this->emptyHex('town-target', ['c']),
            $this->emptyHex('water', ['a', 'remote'], TerrainType::Water),
            $this->emptyHex('remote', ['water']),
        ];
        foreach ([0, 1, 2] as $index) {
            $state->board->hexes[$index]->building->type = BuildingType::Guild;
        }

        $selected = app(\App\Domain\Automation\Services\GameActionSelector::class)->execute($state, 1, \App\Domain\Game\Enums\GameBotDifficulty::Fast);

        $this->assertNotNull($selected);
        $this->assertInstanceOf(BuildWorkshopOptionData::class, $selected->option);
        $this->assertSame('town-target', $selected->option->hexId);
        $this->assertSame(PendingInteractionType::ChooseTown, $selected->simulation->state->pendingInteraction?->type);
        $this->assertSame([], $state->players[0]->townTileIds);
    }

    public function test_a_fast_bot_prepares_a_town_instead_of_only_taking_cheaper_river_land(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 2;
        $state->players[0]->resources = new PlayerResourcesData(tools: 4, coins: 2);
        $state->players[0]->shippingLevel = 1;
        $state->availableTownTileIds = [TownTile::Coins->value];
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['b', 'water']),
            $this->buildingHex('b', 1, ['a', 'town-target']),
            $this->emptyHex('town-target', ['b', 'future'], TerrainType::Mountain),
            $this->emptyHex('future', ['town-target']),
            $this->emptyHex('water', ['a', 'remote'], TerrainType::Water),
            $this->emptyHex('remote', ['water']),
        ];
        foreach ([0, 1] as $index) {
            $state->board->hexes[$index]->building->type = BuildingType::Guild;
        }

        $selector = app(\App\Domain\Automation\Services\GameActionSelector::class);
        $early = $selector->execute($state, 1, \App\Domain\Game\Enums\GameBotDifficulty::Fast);
        $this->assertNotNull($early);
        $this->assertInstanceOf(BuildWorkshopOptionData::class, $early->option);
        $this->assertSame('remote', $early->option->hexId);
        $state->round->number = 4;
        $selected = $selector->execute($state, 1, \App\Domain\Game\Enums\GameBotDifficulty::Fast);

        $this->assertNotNull($selected);
        $this->assertInstanceOf(PaidTerraformingOptionData::class, $selected->option);
        $this->assertSame('town-target', $selected->option->hexId);
    }

    public function test_board_position_priority_prefers_a_connected_building(): void
    {
        $before = $this->state();
        $before->board->hexes = [
            $this->buildingHex('a', 1, ['b']),
            $this->emptyHex('b', ['a']),
            $this->emptyHex('c'),
        ];
        $connected = $before->deepCopy();
        $connected->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $isolated = $before->deepCopy();
        $isolated->board->hexes[2]->building = new BuildingStateData(BuildingType::Workshop, 1);

        $evaluator = app(BoardPositionProgressEvaluator::class);

        $this->assertGreaterThan(
            $evaluator->execute($before, $isolated, 1),
            $evaluator->execute($before, $connected, 1),
        );
    }

    public function test_board_position_strongly_values_an_upgrade_towards_a_town(): void
    {
        $before = $this->state();
        $before->round->number = 4;
        $before->board->hexes = [
            $this->buildingHex('a', 1, ['b']),
            $this->buildingHex('b', 1, ['a', 'c']),
            $this->buildingHex('c', 1, ['b', 'd']),
            $this->buildingHex('d', 1, ['c']),
        ];
        $after = $before->deepCopy();
        $after->board->hexes[0]->building->type = BuildingType::Guild;

        $this->assertSame(
            72,
            app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1),
        );
    }

    public function test_board_position_does_not_value_power_beyond_the_standard_town_requirement(): void
    {
        $before = $this->state();
        $before->board->hexes = [
            $this->buildingHex('a', 1, ['b']),
            $this->buildingHex('b', 1, ['a', 'c']),
            $this->buildingHex('c', 1, ['b', 'd']),
            $this->buildingHex('d', 1, ['c']),
        ];
        foreach ($before->board->hexes as $hex) {
            $hex->building->type = BuildingType::Guild;
        }
        $after = $before->deepCopy();
        $after->board->hexes[0]->building->type = BuildingType::Palace;

        $this->assertSame(
            0,
            app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1),
        );
    }

    public function test_board_position_priority_values_new_reachable_homeland(): void
    {
        $before = $this->state();
        $before->players[0]->resources = new PlayerResourcesData(coins: 2, tools: 1);
        $before->round->phase = GamePhase::Actions;
        $before->board->hexes = [
            $this->buildingHex('a', 1, ['water']),
            $this->emptyHex('water', ['a', 'target'], TerrainType::Water),
            $this->emptyHex('target', ['water']),
        ];
        $after = $before->deepCopy();
        $after->players[0]->shippingLevel = 1;

        $this->assertSame(
            13,
            app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1),
        );
    }

    public function test_board_position_priority_values_affordable_terraforming_opportunities(): void
    {
        $before = $this->state();
        $before->board->hexes = [$this->buildingHex('a', 1, ['target'])];
        $affordable = $before->deepCopy();
        $affordable->board->hexes[] = $this->emptyHex('target', ['a'], TerrainType::Mountain);
        $expensive = $before->deepCopy();
        $expensive->board->hexes[] = $this->emptyHex('target', ['a'], TerrainType::Wasteland);

        $evaluator = app(BoardPositionProgressEvaluator::class);

        $this->assertGreaterThan(
            $evaluator->execute($before, $expensive, 1),
            $evaluator->execute($before, $affordable, 1),
        );
    }

    public function test_board_position_priority_values_expansion_directions(): void
    {
        $before = $this->state();
        $before->board->hexes = [$this->buildingHex('a', 1, ['target'])];
        $deadEnd = $before->deepCopy();
        $deadEnd->board->hexes[] = $this->emptyHex('target', ['a']);
        $open = $before->deepCopy();
        $open->board->hexes = [
            ...$open->board->hexes,
            $this->emptyHex('target', ['a', 'next-1', 'next-2']),
            $this->emptyHex('next-1', ['target']),
            $this->emptyHex('next-2', ['target']),
        ];

        $evaluator = app(BoardPositionProgressEvaluator::class);

        $this->assertGreaterThan(
            $evaluator->execute($before, $deadEnd, 1),
            $evaluator->execute($before, $open, 1),
        );
    }

    public function test_board_position_priority_values_claimed_contested_positions(): void
    {
        $before = $this->state();
        $before->board->hexes = [
            $this->buildingHex('a', 1, []),
            $this->buildingHex('b', 2, []),
        ];
        $after = $before->deepCopy();
        $after->board->hexes[0]->adjacentHexIds = ['b'];
        $after->board->hexes[1]->adjacentHexIds = ['a'];

        $this->assertSame(
            5,
            app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1),
        );
    }

    public function test_board_position_priority_values_positions_one_expansion_from_an_opponent(): void
    {
        $before = $this->state();
        $before->board->hexes = [
            $this->buildingHex('own', 1, ['middle']),
            $this->emptyHex('middle', ['own']),
            $this->buildingHex('opponent', 2, []),
        ];
        $after = $before->deepCopy();
        $after->board->hexes[1]->adjacentHexIds = ['own', 'opponent'];
        $after->board->hexes[2]->adjacentHexIds = ['middle'];

        $this->assertSame(
            6,
            app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1),
        );
    }

    public function test_board_position_penalizes_completed_terraforming_when_no_workshop_is_available(): void
    {
        $before = $this->state();
        $before->board->hexes = [$this->emptyHex('target', [], TerrainType::Mountain)];
        for ($index = 0; $index < BuildingType::Workshop->supplyLimit(); $index++) {
            $before->board->hexes[] = $this->buildingHex('workshop-'.$index, 1, []);
        }
        $after = $before->deepCopy();
        $after->board->hexes[0]->terrain = $after->players[0]->homeland;

        $this->assertLessThan(
            0,
            app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1),
        );
    }

    public function test_bot_does_not_pay_to_finish_terraforming_when_no_workshop_is_available(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(coins: 20, tools: 10);
        $state->board->hexes = [
            $this->emptyHex('target', ['workshop-0'], TerrainType::Mountain),
        ];
        for ($index = 0; $index < BuildingType::Workshop->supplyLimit(); $index++) {
            $state->board->hexes[] = $this->buildingHex(
                'workshop-'.$index,
                1,
                $index === 0 ? ['target'] : [],
            );
        }

        $ranked = app(GameActionRanker::class)->execute(
            $state,
            1,
            depth: 1,
            auxiliaryActionsRemaining: 0,
        );

        $this->assertNotInstanceOf(PaidTerraformingOptionData::class, $ranked[0]->option);
    }

    public function test_final_scoring_priority_values_improved_projected_rank(): void
    {
        $before = $this->state();
        $before->players[0]->knowledge->banking = 4;
        $before->players[] = new GamePlayerStateData(
            playerId: 2,
            userId: 20,
            color: PlayerColor::Red,
            faction: Faction::Inventors,
            homeland: TerrainType::Wasteland,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 5),
        );
        $unchanged = $before->deepCopy();
        $after = $before->deepCopy();
        $after->players[0]->knowledge->banking = 5;

        $evaluator = app(FinalScoringProgressEvaluator::class);

        $this->assertSame(0, $evaluator->execute($before, $unchanged, 1));
        $this->assertSame(2, $evaluator->execute($before, $after, 1));
    }

    public function test_final_scoring_priority_values_larger_connected_network(): void
    {
        $before = $this->state();
        $before->players[] = new GamePlayerStateData(
            playerId: 2,
            userId: 20,
            color: PlayerColor::Red,
            faction: Faction::Inventors,
            homeland: TerrainType::Wasteland,
            roundBonus: RoundBonus::Coins,
        );
        $before->board->hexes = [
            $this->buildingHex('a', 1, ['b']),
            $this->buildingHex('c', 2, ['d']),
            $this->buildingHex('d', 2, ['c']),
        ];
        $after = $before->deepCopy();
        $after->board->hexes[] = $this->buildingHex('b', 1, ['a']);

        $this->assertSame(
            3,
            app(FinalScoringProgressEvaluator::class)->execute($before, $after, 1),
        );
    }

    public function test_round_scoring_priority_only_values_new_matching_progress(): void
    {
        $before = $this->state();
        $before->round->phase = GamePhase::Actions;
        $before->round->scoringTileId = RoundScoringTile::WorkshopLaw->value;
        $before->board->hexes = [
            new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Plains,
                terrain: TerrainType::Plains,
                building: new BuildingStateData(BuildingType::Workshop, 1),
            ),
        ];
        $unchanged = $before->deepCopy();
        $after = $before->deepCopy();
        $after->board->hexes[] = new BoardHexStateData(
            id: '1:0',
            q: 1,
            r: 0,
            initialTerrain: TerrainType::Plains,
            terrain: TerrainType::Plains,
            building: new BuildingStateData(BuildingType::Workshop, 1),
        );

        $evaluator = app(RoundScoringProgressEvaluator::class);

        $this->assertSame(0, $evaluator->execute($before, $unchanged, 1));
        $this->assertSame(2, $evaluator->execute($before, $after, 1));

        $before->round->scoringTileId = RoundScoringTile::GuildLaw->value;

        $this->assertSame(0, $evaluator->execute($before, $after, 1));
    }

    public function test_it_ranks_simulated_actions_by_resulting_state_value(): void
    {
        $state = $this->state();
        $state->round->phase = GamePhase::Setup;
        $state->players[0]->knowledge->banking = 2;
        $state->players[0]->knowledge->unassignedSteps = 1;
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChooseStartingResources,
            1,
            context: ['bookCount' => 0, 'knowledgeStepCount' => 1],
        );

        $rankedActions = app(GameActionRanker::class)->execute($state, 1);

        $this->assertCount(4, $rankedActions);
        $this->assertInstanceOf(RewardDistributionOptionData::class, $rankedActions[0]->option);
        $this->assertSame(1, array_sum($rankedActions[0]->option->knowledgeCounts));
        $this->assertSame(
            3,
            array_sum([
                $rankedActions[0]->simulation->state->players[0]->knowledge->banking,
                $rankedActions[0]->simulation->state->players[0]->knowledge->law,
                $rankedActions[0]->simulation->state->players[0]->knowledge->engineering,
                $rankedActions[0]->simulation->state->players[0]->knowledge->medicine,
            ]),
        );
        $this->assertSame(2, $state->players[0]->knowledge->banking);
        $this->assertGreaterThanOrEqual($rankedActions[1]->score, $rankedActions[0]->score);
    }

    public function test_state_evaluation_rewards_resources_and_progress(): void
    {
        $state = $this->state();
        $evaluator = app(GameStateEvaluator::class);
        $initialScore = $evaluator->execute($state, 1);
        $initialBreakdown = $evaluator->evaluateWithBreakdown($state, 1);

        $state->players[0]->resources->tools++;
        $state->players[0]->knowledge->law++;

        $this->assertGreaterThan($initialScore, $evaluator->execute($state, 1));
        $breakdown = $evaluator->evaluateWithBreakdown($state, 1);
        $this->assertSame(30, $breakdown->resources - $initialBreakdown->resources);
        $this->assertSame(10, $breakdown->development - $initialBreakdown->development);
        $this->assertSame($evaluator->execute($state, 1), $breakdown->total());
    }

    public function test_resource_value_transitions_to_final_conversion_in_the_last_two_rounds(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 4);
        $evaluator = app(GameStateEvaluator::class);

        $state->round->number = 4;
        $this->assertSame(180, $evaluator->evaluateWithBreakdown($state, 1)->resources);
        $state->round->number = 5;
        $this->assertSame(100, $evaluator->evaluateWithBreakdown($state, 1)->resources);
        $state->round->number = 6;
        $this->assertSame(20, $evaluator->evaluateWithBreakdown($state, 1)->resources);

        $state->players[0]->resources->power = new PowerBowlsStateData(bowlOne: 4, bowlTwo: 4, bowlThree: 4);
        $this->assertSame(32, $evaluator->evaluateWithBreakdown($state, 1)->resources);
    }

    public function test_final_scoring_forecast_is_not_counted_twice_when_the_game_finishes(): void
    {
        $state = $this->state();
        $state->round->number = 6;
        $state->players[0]->knowledge->banking = 3;
        $state->board->hexes = [$this->buildingHex('workshop', 1, [])];
        $evaluator = app(GameStateEvaluator::class);
        $before = $evaluator->evaluateWithBreakdown($state, 1);
        $this->assertSame(260, $before->projectedFinalScoring);

        app(ApplyFinalScoringAction::class)->execute($state);
        $state->round->phase = GamePhase::Finished;
        $after = $evaluator->evaluateWithBreakdown($state, 1);

        $this->assertSame(0, $after->projectedFinalScoring);
        $this->assertSame($before->total(), $after->total());
    }

    public function test_endgame_knowledge_is_valuable_when_it_improves_the_final_ranking(): void
    {
        $state = $this->state();
        $state->round->number = 6;
        $state->players[0]->knowledge->banking = 3;
        $state->players[] = new GamePlayerStateData(
            2,
            null,
            PlayerColor::Red,
            Faction::Inventors,
            TerrainType::Wasteland,
            RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 4)
        );
        $evaluator = app(GameStateEvaluator::class);
        $before = $evaluator->execute($state, 1);
        $state->players[0]->knowledge->banking = 5;

        $this->assertSame(80, $evaluator->execute($state, 1) - $before);
    }

    public function test_state_evaluation_penalizes_the_strongest_opponents_progress(): void
    {
        $state = $this->state();
        $state->players[] = new GamePlayerStateData(
            playerId: 2,
            userId: 20,
            color: PlayerColor::Red,
            faction: Faction::Inventors,
            homeland: TerrainType::Wasteland,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(),
        );
        $evaluator = app(GameStateEvaluator::class);
        $initialScore = $evaluator->execute($state, 1);

        $state->players[1]->victoryPoints++;

        $this->assertLessThan($initialScore, $evaluator->execute($state, 1));
    }

    public function test_state_evaluation_values_recurring_income_by_remaining_rounds(): void
    {
        $earlyIncomeState = $this->state();
        $earlyIncomeState->round->number = 1;
        $earlyIncomeState->players[0]->competencyIds = [Competency::Competency01->value];
        $earlyNoIncomeState = $this->state();
        $earlyNoIncomeState->round->number = 1;
        $earlyNoIncomeState->players[0]->competencyIds = [Competency::Competency04->value];

        $finalIncomeState = $this->state();
        $finalIncomeState->round->number = 6;
        $finalIncomeState->players[0]->competencyIds = [Competency::Competency01->value];
        $finalNoIncomeState = $this->state();
        $finalNoIncomeState->round->number = 6;
        $finalNoIncomeState->players[0]->competencyIds = [Competency::Competency04->value];

        $evaluator = app(GameStateEvaluator::class);
        $earlyIncomeAdvantage = $evaluator->execute($earlyIncomeState, 1)
            - $evaluator->execute($earlyNoIncomeState, 1);
        $finalIncomeAdvantage = $evaluator->execute($finalIncomeState, 1)
            - $evaluator->execute($finalNoIncomeState, 1);

        $this->assertGreaterThan($finalIncomeAdvantage, $earlyIncomeAdvantage);
        $this->assertSame(0, $finalIncomeAdvantage);
    }

    public function test_next_round_income_uses_endgame_resource_values_in_round_six(): void
    {
        $before = $this->state();
        $before->round->number = 5;
        $after = $before->deepCopy();
        $after->players[0]->inventionIds = [Innovation::Guild->value];
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(10, $evaluator->evaluateWithBreakdown($after, 1)->futureIncome
            - $evaluator->evaluateWithBreakdown($before, 1)->futureIncome);
        $this->assertSame(0, $evaluator->evaluateWithBreakdown($after, 1)->development
            - $evaluator->evaluateWithBreakdown($before, 1)->development);
    }

    public function test_knowledge_income_does_not_outweigh_recurring_victory_points_and_coins(): void
    {
        $engineIncomeState = $this->state();
        $engineIncomeState->round->number = 1;
        $engineIncomeState->players[0]->competencyIds = [Competency::Competency01->value];

        $victoryPointIncomeState = $this->state();
        $victoryPointIncomeState->round->number = 1;
        $victoryPointIncomeState->players[0]->competencyIds = [Competency::Competency02->value];

        $evaluator = app(GameStateEvaluator::class);

        $this->assertGreaterThan(
            $evaluator->execute($engineIncomeState, 1),
            $evaluator->execute($victoryPointIncomeState, 1),
        );
        $this->assertSame(
            188,
            $evaluator->evaluateWithBreakdown($victoryPointIncomeState, 1)->futureIncome
                - $evaluator->evaluateWithBreakdown($engineIncomeState, 1)->futureIncome,
        );
    }

    public function test_state_evaluation_does_not_overvalue_a_small_victory_point_gain(): void
    {
        $victoryPointState = $this->state();
        $victoryPointState->players[0]->victoryPoints = 3;

        $scholarState = $this->state();
        $scholarState->players[0]->resources->scholars = 1;

        $evaluator = app(GameStateEvaluator::class);

        $this->assertGreaterThan(
            $evaluator->execute($victoryPointState, 1),
            $evaluator->execute($scholarState, 1),
        );
    }

    public function test_state_evaluation_does_not_treat_round_bonus_income_as_recurring(): void
    {
        $coinsState = $this->state();
        $coinsState->players[0]->roundBonus = RoundBonus::Coins;
        $noIncomeState = $this->state();
        $noIncomeState->players[0]->roundBonus = RoundBonus::RiverWorkshop;

        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(
            $evaluator->execute($noIncomeState, 1),
            $evaluator->execute($coinsState, 1),
        );
    }

    public function test_selected_round_bonus_income_is_forecast_exactly_once(): void
    {
        $coinsState = $this->state();
        $coinsState->round->phase = GamePhase::Actions;
        $coinsState->round->number = 1;
        $coinsState->passedPlayerIds = [1];
        $coinsState->players[0]->roundBonus = RoundBonus::Coins;
        $noIncomeState = $coinsState->deepCopy();
        $noIncomeState->players[0]->roundBonus = RoundBonus::RiverWorkshop;
        $evaluator = app(GameStateEvaluator::class);

        foreach ([GamePhase::Setup, GamePhase::Actions, GamePhase::ScienceBonus] as $phase) {
            $coinsState->round->phase = $phase;
            $noIncomeState->round->phase = $phase;
            $this->assertSame(
                60,
                $evaluator->evaluateWithBreakdown($coinsState, 1)->futureIncome
                    - $evaluator->evaluateWithBreakdown($noIncomeState, 1)->futureIncome,
            );
        }

        foreach ([GamePhase::Income, GamePhase::Finished] as $phase) {
            $coinsState->round->phase = $phase;
            $noIncomeState->round->phase = $phase;
            $this->assertSame(
                $evaluator->evaluateWithBreakdown($noIncomeState, 1)->futureIncome,
                $evaluator->evaluateWithBreakdown($coinsState, 1)->futureIncome,
            );
        }
    }

    public function test_a_one_ply_forecast_completes_the_round_bonus_choice_after_passing(): void
    {
        $state = $this->state();
        $opponent = clone $state->players[0];
        $opponent->playerId = 2;
        $state->players[] = $opponent;
        $state->turnOrder = [1, 2];
        $state->round->number = 1;
        $state->players[0]->roundBonus = RoundBonus::RiverWorkshop;
        $state->players[0]->resources = new PlayerResourcesData();
        $state->board->hexes = [];
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'pass-reward-forecast');
        $state->setupPool->roundScoringTiles = [];
        $state->setupPool->availableRoundBonuses = [new RoundBonusOfferData(RoundBonus::Coins, 3)];
        $ranker = app(GameActionRanker::class);

        $ranked = $ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $pass = collect($ranked)->first(fn ($action): bool => $action->option->type() === GameActionOptionType::Pass);

        $this->assertNotNull($pass);
        $this->assertSame(66, $pass->scoreBreakdown->searchAdjustment);
        $this->assertSame(PendingInteractionType::ChooseRoundBonus, $pass->simulation->state->pendingInteraction?->type);
        $this->assertSame(RoundBonus::RiverWorkshop, $state->players[0]->roundBonus);
        $this->assertSame(0, $state->players[0]->resources->coins);
        $this->assertSame([], $state->passedPlayerIds);
        $this->assertCount(1, $state->setupPool->availableRoundBonuses);
    }

    public function test_returned_round_bonus_is_not_forecast_while_choosing_its_replacement(): void
    {
        $coinsState = $this->state();
        $coinsState->round->phase = GamePhase::Actions;
        $coinsState->passedPlayerIds = [1];
        $coinsState->players[0]->roundBonus = RoundBonus::Coins;
        $coinsState->pendingInteraction = new PendingInteractionData(PendingInteractionType::ChooseRoundBonus, 1);
        $noIncomeState = $coinsState->deepCopy();
        $noIncomeState->players[0]->roundBonus = RoundBonus::RiverWorkshop;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame($evaluator->execute($noIncomeState, 1), $evaluator->execute($coinsState, 1));
        $coinsState->pendingInteraction = null;
        $noIncomeState->pendingInteraction = null;
        $coinsState->round->number = 6;
        $noIncomeState->round->number = 6;
        $this->assertSame($evaluator->execute($noIncomeState, 1), $evaluator->execute($coinsState, 1));
    }

    public function test_the_next_bonus_income_is_not_a_reward_exclusive_to_passing_immediately(): void
    {
        $state = $this->state();
        $state->round->number = 1;
        $state->players[0]->roundBonus = RoundBonus::RiverWorkshop;
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'next-bonus-continuity');
        $state->setupPool->roundScoringTiles = [];
        $state->setupPool->availableRoundBonuses = [new RoundBonusOfferData(RoundBonus::Coins, 0)];
        $evaluator = app(GameStateEvaluator::class);
        $beforePassing = $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;
        $state->passedPlayerIds = [1];
        $state->pendingInteraction = new PendingInteractionData(PendingInteractionType::ChooseRoundBonus, 1);
        $whileChoosing = $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;
        $state->pendingInteraction = null;
        $state->players[0]->roundBonus = RoundBonus::Coins;
        $afterChoosing = $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;

        $this->assertSame($afterChoosing, $beforePassing);
        $this->assertSame($afterChoosing, $whileChoosing);
    }

    public function test_an_early_discounted_guild_upgrade_can_pay_for_itself_through_income(): void
    {
        $workshopState = $this->state();
        $workshopState->round->number = 1;
        $workshopState->board->hexes = [$this->buildingHex('home', 1, [])];
        $workshopState->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 3, power: new PowerBowlsStateData(bowlOne: 12));
        $guildState = $workshopState->deepCopy();
        $guildState->board->hexes[0]->building->type = BuildingType::Guild;
        $guildState->players[0]->resources->coins -= 3;
        $guildState->players[0]->resources->tools -= 2;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertGreaterThan(
            $evaluator->execute($workshopState, 1),
            $evaluator->execute($guildState, 1),
        );
        $this->assertSame(99, $evaluator->evaluateWithBreakdown($guildState, 1)->futureIncome
            - $evaluator->evaluateWithBreakdown($workshopState, 1)->futureIncome);
    }

    public function test_two_targeted_conversions_can_fund_a_workshop_without_consuming_a_search_ply(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 0,
            tools: 1,
            power: new PowerBowlsStateData(bowlThree: 2),
        );
        $state->board->hexes = [
            $this->buildingHex('home', 1, ['target']),
            $this->emptyHex('target', ['home'], TerrainType::Forest),
        ];
        $ranker = app(GameActionRanker::class);
        $first = $ranker->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 2)[0];
        $this->assertSame(GameActionOptionType::ExchangeResources, $first->option->type());
        $second = $ranker->execute($first->simulation->state, 1, depth: 1, auxiliaryActionsRemaining: 1)[0];
        $this->assertSame(GameActionOptionType::ExchangeResources, $second->option->type());
        $construction = $ranker->execute($second->simulation->state, 1, depth: 1, auxiliaryActionsRemaining: 0)[0];
        $this->assertSame(GameActionOptionType::BuildWorkshop, $construction->option->type());
        $this->assertSame('target', $construction->option->hexId);
        $this->assertSame(0, $state->players[0]->resources->coins);
        $this->assertSame(2, $state->players[0]->resources->power->bowlThree);
    }

    public function test_sacrificing_power_below_the_reusable_action_capacity_has_a_future_cost(): void
    {
        $before = $this->state();
        $before->round->number = 1;
        $before->players[0]->resources->power = new PowerBowlsStateData(bowlTwo: 6);
        $after = $before->deepCopy();
        $after->players[0]->resources->power = new PowerBowlsStateData(bowlTwo: 4, bowlThree: 1);
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(-12, $evaluator->execute($after, 1) - $evaluator->execute($before, 1));
        $before->round->number = 6;
        $after->round->number = 6;
        $this->assertSame(0, $evaluator->execute($after, 1) - $evaluator->execute($before, 1));

        $before->round->number = 1;
        $after->round->number = 1;
        $before->players[0]->resources->power = new PowerBowlsStateData(bowlTwo: 9);
        $after->players[0]->resources->power = new PowerBowlsStateData(bowlTwo: 7, bowlThree: 1);
        $this->assertSame(-2, $evaluator->execute($after, 1) - $evaluator->execute($before, 1));
        $before->players[0]->faction = Faction::Illusionists;
        $after->players[0]->faction = Faction::Illusionists;
        $before->players[0]->resources->power = new PowerBowlsStateData(bowlTwo: 6);
        $after->players[0]->resources->power = new PowerBowlsStateData(bowlTwo: 4, bowlThree: 1);
        $this->assertSame(-2, $evaluator->execute($after, 1) - $evaluator->execute($before, 1));
    }

    public function test_book_planning_favors_a_useful_innovation_over_completing_a_worthless_one(): void
    {
        $state = $this->state();
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'useful-book-planning');
        $state->setupPool->innovations[0] = Innovation::Telecommunication;
        $state->setupPool->innovations[5] = Innovation::Professor;
        $state->availableInventionIds = [Innovation::Telecommunication->value, Innovation::Professor->value];
        $state->players[0]->resources->books = new BookSupplyData(banking: 2, law: 2, medicine: 1);
        $usefulInnovationState = $state->deepCopy();
        $usefulInnovationState->players[0]->resources->books->law--;
        $usefulInnovationState->players[0]->resources->books->medicine++;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(94, $evaluator->execute($usefulInnovationState, 1) - $evaluator->execute($state, 1));
        $this->assertSame(2, $state->players[0]->resources->books->law);
        $this->assertSame(1, $state->players[0]->resources->books->medicine);
    }

    public function test_book_planning_does_not_promise_an_innovation_after_the_final_round(): void
    {
        $state = $this->state();
        $state->round->number = 5;
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(3, 'innovation-deadline');
        $state->setupPool->innovations[0] = Innovation::Library;
        $state->availableInventionIds = [Innovation::Library->value];
        $state->players[0]->knowledge = new KnowledgeStateData(banking: 12, law: 12);
        $state->players[0]->resources = new PlayerResourcesData(coins: 5, books: new BookSupplyData(banking: 2, law: 1));
        $withoutInnovation = $state->deepCopy();
        $withoutInnovation->availableInventionIds = [];
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(
            $evaluator->evaluateWithBreakdown($withoutInnovation, 1)->development,
            $evaluator->evaluateWithBreakdown($state, 1)->development
        );
        $state->players[0]->resources->books->law++;
        $this->assertGreaterThan(
            $evaluator->evaluateWithBreakdown($withoutInnovation, 1)->development,
            $evaluator->evaluateWithBreakdown($state, 1)->development
        );
        $this->assertSame(5, $state->round->number);
    }

    public function test_passing_does_not_hide_an_innovation_action_planned_for_the_next_round(): void
    {
        $state = $this->state();
        $state->round->number = 2;
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'innovation-after-passing');
        $state->setupPool->innovations[5] = Innovation::Professor;
        $state->availableInventionIds = [Innovation::Professor->value];
        $state->players[0]->resources = new PlayerResourcesData(coins: 5, books: new BookSupplyData(medicine: 2, engineering: 2));
        $afterPassing = $state->deepCopy();
        $afterPassing->passedPlayerIds = [1];
        $afterPassing->pendingInteraction = new PendingInteractionData(PendingInteractionType::ChooseRoundBonus, 1);
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(38, $evaluator->evaluateWithBreakdown($state, 1)->development);
        $this->assertSame(
            $evaluator->evaluateWithBreakdown($state, 1)->development,
            $evaluator->evaluateWithBreakdown($afterPassing, 1)->development
        );
        $this->assertSame([1], $afterPassing->passedPlayerIds);
        $this->assertSame(2, $afterPassing->round->number);
        $this->assertSame([], $state->passedPlayerIds);
    }

    public function test_founding_a_town_does_not_erase_its_accumulated_board_position_value(): void
    {
        $before = $this->townUpgradeState();
        $before->board->hexes[2]->building->type = BuildingType::Guild;
        $after = $before->deepCopy();
        foreach ($after->board->hexes as $hex) {
            $hex->townId = 'completed-town';
        }
        $after->players[0]->townTileIds = [TownTile::Coins->value];

        $this->assertSame(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
        $this->assertNull($before->board->hexes[0]->townId);
    }

    public function test_a_small_reward_budget_does_not_starve_palace_forecasts_behind_terraforming_targets(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 4);
        $state->availablePalaceIds = [PalaceAbility::Palace10->value];
        $state->availableCompetencyIds = [];
        $state->board->hexes = [
            $this->buildingHex('guild', 1, []),
            $this->buildingHex('home', 1, ['target']),
            $this->emptyHex('target', ['home'], TerrainType::Mountain),
        ];
        $state->board->hexes[0]->building->type = BuildingType::Guild;
        $ranker = app(GameActionRanker::class);
        $ranked = $ranker->execute($state, 1, depth: 1, maxNodes: 3, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame(BuildingType::Palace, $ranked[0]->option->target);
        $this->assertGreaterThan(400, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertTrue($ranker->lastBudgetExhausted());
        $this->assertNull($state->players[0]->palaceId);
    }

    public function test_early_provisional_knowledge_ranks_do_not_consume_an_almost_complete_professor_book_set(): void
    {
        $state = $this->state();
        $state->round->number = 2;
        $state->turnOrder = [1, 2];
        $state->players[0]->knowledge = new KnowledgeStateData(banking: 1, law: 1, engineering: 1, medicine: 1);
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 5,
            books: new BookSupplyData(medicine: 2, engineering: 2),
            power: new PowerBowlsStateData(bowlOne: 4, bowlTwo: 8),
        );
        $opponent = $state->deepCopy()->players[0];
        $opponent->playerId = 2;
        $opponent->resources = new PlayerResourcesData();
        $state->players[] = $opponent;
        $state->neutralKnowledge = new NeutralKnowledgeStateData(
            PlayerColor::Black,
            new KnowledgeStateData(banking: 3, law: 3, engineering: 3, medicine: 3),
            [],
        );
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'save-books-for-professor');
        $state->setupPool->roundScoringTiles = [];
        $state->setupPool->innovations[5] = Innovation::Professor;
        $state->setupPool->bookActions = [BookAction::AdvanceKnowledge];
        $state->availableInventionIds = [Innovation::Professor->value];
        $state->round->usedSharedActionIds = array_map(fn (PowerAction $action): string => $action->value, PowerAction::cases());

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::Pass, $ranked[0]->option->type());
        $this->assertSame(2, $ranked[0]->simulation->state->players[0]->resources->books->medicine);
        $this->assertSame(2, $ranked[0]->simulation->state->players[0]->resources->books->engineering);
        $this->assertSame(4, app(PassValueEvaluator::class)->execute($state, 1));
        $state->players[0]->resources->coins = 7;
        $this->assertSame(6, app(PassValueEvaluator::class)->execute($state, 1));
        $state->players[0]->resources->coins = 5;
        $state->players[0]->palaceId = PalaceAbility::Palace10->value;
        $this->assertSame(9, app(PassValueEvaluator::class)->execute($state, 1));
        $state->players[0]->palaceId = null;
        $state->availableInventionIds = [];
        $this->assertSame(21, app(PassValueEvaluator::class)->execute($state, 1));
        $state->availableInventionIds = [Innovation::Professor->value];
        $state->round->number = 6;
        $this->assertSame(63, app(PassValueEvaluator::class)->execute($state, 1));
    }

    public function test_the_final_round_does_not_reward_shipping_without_points_or_expansion(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 6;
        $state->players[0]->shippingLevel = 1;
        $state->players[0]->knowledge = new KnowledgeStateData(banking: 12, law: 12, engineering: 12, medicine: 12);
        $state->players[0]->resources = new PlayerResourcesData(coins: 4, scholars: 1);
        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::Pass, $ranked[0]->option->type());
        $this->assertSame(0, $ranked[0]->scoreBreakdown->passPenalty);
        $this->assertSame(1, $ranked[0]->simulation->state->players[0]->shippingLevel);
    }

    public function test_shipping_is_not_rewarded_for_more_identical_targets_when_good_expansion_is_already_available(): void
    {
        $before = $this->state();
        $before->players[0]->resources = new PlayerResourcesData(coins: 2, tools: 1);
        $before->board->hexes = [
            $this->buildingHex('home', 1, ['a', 'b', 'c', 'water']),
            $this->emptyHex('a', ['home']),
            $this->emptyHex('b', ['home']),
            $this->emptyHex('c', ['home']),
            $this->emptyHex('water', ['home', 'd', 'e', 'f'], TerrainType::Water),
            $this->emptyHex('d', ['water']),
            $this->emptyHex('e', ['water']),
            $this->emptyHex('f', ['water']),
        ];
        $after = $before->deepCopy();
        $after->players[0]->shippingLevel = 1;
        $evaluator = app(BoardPositionProgressEvaluator::class);

        $this->assertSame(0, $evaluator->execute($before, $after, 1));
        foreach ([0, 1, 2] as $index) {
            $before->board->hexes[$index + 1]->terrain = TerrainType::Wasteland;
            $after->board->hexes[$index + 1]->terrain = TerrainType::Wasteland;
        }
        $this->assertGreaterThan(0, $evaluator->execute($before, $after, 1));
    }

    public function test_science_bonus_thresholds_have_value_without_improving_a_final_rank(): void
    {
        $before = $this->state();
        $before->players[0]->knowledge->law = 2;
        $before->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'science-threshold-value');
        $before->setupPool->roundScoringTiles = [RoundScoringTile::WorkshopLaw];
        $before->round->scoringTileId = RoundScoringTile::WorkshopLaw->value;
        $after = $before->deepCopy();
        $after->players[0]->knowledge->law = 4;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(40, $evaluator->evaluateWithBreakdown($after, 1)->futureIncome
            - $evaluator->evaluateWithBreakdown($before, 1)->futureIncome);
        $this->assertSame(
            $evaluator->evaluateWithBreakdown($before, 1)->projectedFinalScoring,
            $evaluator->evaluateWithBreakdown($after, 1)->projectedFinalScoring
        );
    }

    public function test_science_bonus_and_school_income_share_the_same_scholar_pool(): void
    {
        $before = $this->state();
        $before->round->number = 3;
        $before->round->scoringTileId = RoundScoringTile::WorkshopLaw->value;
        $before->players[0]->scholarPoolSize = 1;
        $before->players[0]->faction = Faction::Inventors;
        $before->players[0]->knowledge->law = 3;
        $before->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'joint-scholar-income');
        $before->setupPool->roundScoringTiles = array_fill(0, 6, RoundScoringTile::WorkshopLaw);
        $after = $before->deepCopy();
        $after->board->hexes = [$this->buildingHex('school', 1, [])];
        $after->board->hexes[0]->building->type = BuildingType::School;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(
            $evaluator->evaluateWithBreakdown($before, 1)->futureIncome,
            $evaluator->evaluateWithBreakdown($after, 1)->futureIncome,
        );
        $before->players[0]->scholarPoolSize = 2;
        $after->players[0]->scholarPoolSize = 2;
        $this->assertSame(63, $evaluator->evaluateWithBreakdown($after, 1)->futureIncome
            - $evaluator->evaluateWithBreakdown($before, 1)->futureIncome);
        $before->players[0]->scholarPoolSize = 1;
        $after->players[0]->scholarPoolSize = 1;
        foreach ([$before, $after] as $state) {
            $state->round->phase = GamePhase::ScienceBonus;
            $state->players[0]->resources->scholars = 1;
            $state->round->scienceBonusReceipts = [['round' => 3, 'player_id' => 1, 'scholars' => 1]];
        }
        $this->assertSame(
            $evaluator->evaluateWithBreakdown($before, 1)->futureIncome,
            $evaluator->evaluateWithBreakdown($after, 1)->futureIncome,
        );
    }

    public function test_power_income_has_no_value_without_tokens_that_can_be_charged(): void
    {
        $before = $this->state();
        $before->round->number = 3;
        $after = $before->deepCopy();
        $after->players[0]->palaceId = PalaceAbility::Palace15->value;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(
            $evaluator->evaluateWithBreakdown($before, 1)->futureIncome,
            $evaluator->evaluateWithBreakdown($after, 1)->futureIncome
        );
        $after->players[0]->resources->power->bowlOne = 6;
        $this->assertSame(72, $evaluator->evaluateWithBreakdown($after, 1)->futureIncome
            - $evaluator->evaluateWithBreakdown($before, 1)->futureIncome);
    }

    public function test_science_bonus_and_regular_income_share_the_same_power_charging_capacity(): void
    {
        $before = $this->state();
        $before->round->number = 3;
        $before->round->scoringTileId = RoundScoringTile::WorkshopBanking->value;
        $before->players[0]->knowledge->banking = 3;
        $before->players[0]->resources->power->bowlOne = 1;
        $before->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'joint-power-income');
        $before->setupPool->roundScoringTiles = array_fill(0, 6, RoundScoringTile::WorkshopBanking);
        $after = $before->deepCopy();
        $after->players[0]->palaceId = PalaceAbility::Palace15->value;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(
            $evaluator->evaluateWithBreakdown($before, 1)->futureIncome,
            $evaluator->evaluateWithBreakdown($after, 1)->futureIncome
        );
    }

    public function test_the_last_round_bonus_choice_prefers_real_pass_points_to_unspent_income(): void
    {
        $coinsState = $this->state();
        $coinsState->round->number = 5;
        $coinsState->passedPlayerIds = [1];
        $coinsState->players[0]->roundBonus = RoundBonus::Coins;
        $coinsState->board->hexes = [$this->buildingHex('university', 1, [])];
        $coinsState->board->hexes[0]->building->type = BuildingType::University;
        $passBonusState = $coinsState->deepCopy();
        $passBonusState->players[0]->roundBonus = RoundBonus::PassPalaceUniversity;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame(30, $evaluator->execute($passBonusState, 1) - $evaluator->execute($coinsState, 1));
        $this->assertSame(5, $coinsState->round->number);
        $this->assertSame(GamePhase::Actions, $coinsState->round->phase);
    }

    public function test_round_bonus_pass_points_are_not_counted_twice_after_passing(): void
    {
        $before = $this->state();
        $before->round->number = 5;
        $before->players[0]->roundBonus = RoundBonus::PassPalaceUniversity;
        $before->board->hexes = [$this->buildingHex('university', 1, [])];
        $before->board->hexes[0]->building->type = BuildingType::University;
        $after = $before->deepCopy();
        $after->players[0]->victoryPoints += 4;
        $after->passedPlayerIds = [1];
        $after->pendingInteraction = new PendingInteractionData(PendingInteractionType::ChooseRoundBonus, 1);
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame($evaluator->execute($before, 1), $evaluator->execute($after, 1));
    }

    public function test_it_returns_no_ranked_actions_when_none_are_legal(): void
    {
        $state = $this->state();
        $state->round->phase = GamePhase::Income;

        $this->assertSame([], app(GameActionRanker::class)->execute($state, 1));
    }

    public function test_it_prefers_pass_to_sacrificing_power_without_a_useful_follow_up(): void
    {
        $state = $this->state();
        $state->round->phase = GamePhase::Actions;
        $state->players[0]->resources->power = new PowerBowlsStateData(bowlTwo: 2);

        $rankedActions = app(GameActionRanker::class)->execute($state, 1, depth: 1);
        $passAction = collect($rankedActions)->first(
            static fn ($action): bool => $action->option->type() === GameActionOptionType::Pass,
        );

        $this->assertNotNull($passAction);
        $this->assertSame(GameActionOptionType::Pass, $rankedActions[0]->option->type());
        $this->assertSame(0, $passAction->scoreBreakdown->passPenalty);
        $this->assertSame($passAction->score, $passAction->scoreBreakdown->total());
    }

    public function test_sacrificing_power_can_unlock_a_useful_power_action(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 0,
            tools: 2,
            power: new PowerBowlsStateData(bowlOne: 2, bowlTwo: 2, bowlThree: 3),
        );
        $state->board->hexes = [$this->buildingHex('home', 1, [])];
        $state->round->usedSharedActionIds = array_values(array_map(
            static fn (PowerAction $action): string => $action->value,
            array_filter(PowerAction::cases(), static fn (PowerAction $action): bool => $action !== PowerAction::GainCoins),
        ));
        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);
        $sacrifice = collect($ranked)->first(static fn ($action): bool => $action->option->type() === GameActionOptionType::SacrificePower);
        $pass = collect($ranked)->first(static fn ($action): bool => $action->option->type() === GameActionOptionType::Pass);

        $this->assertNotNull($sacrifice);
        $this->assertNotNull($pass);
        $this->assertGreaterThan($pass->score, $sacrifice->score);
        $this->assertInstanceOf(PowerActionOptionData::class, $ranked[0]->option);
        $this->assertSame(PowerAction::GainCoins, $ranked[0]->option->action);
        $this->assertSame(1, $ranked[0]->option->sacrificeAmount);
        $continuations = app(GameActionRanker::class)->execute($sacrifice->simulation->state, 1, depth: 1, auxiliaryActionsRemaining: 0);
        $this->assertSame(GameActionOptionType::PowerAction, $continuations[0]->option->type());
        $this->assertSame(PowerAction::GainCoins, $continuations[0]->option->action);
    }

    public function test_it_does_not_penalize_pass_when_it_is_the_only_legal_action(): void
    {
        $state = $this->state();
        $state->round->phase = GamePhase::Actions;
        $state->players[0]->resources->coins = 10;

        $rankedActions = app(GameActionRanker::class)->execute($state, 1, depth: 1);

        $this->assertCount(1, $rankedActions);
        $this->assertSame(GameActionOptionType::Pass, $rankedActions[0]->option->type());
        $this->assertSame(0, $rankedActions[0]->scoreBreakdown->passPenalty);
    }

    public function test_it_searches_the_next_players_response(): void
    {
        $state = $this->state();
        $state->turnOrder = [1, 2];
        $state->players[] = new GamePlayerStateData(
            playerId: 2,
            userId: 20,
            color: PlayerColor::Red,
            faction: Faction::Inventors,
            homeland: TerrainType::Wasteland,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(
                power: new PowerBowlsStateData(bowlOne: 1, bowlTwo: 1),
            ),
        );
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::PowerOffer,
            2,
            context: [
                'buildingPlayerId' => 1,
                'builtHexId' => '0:0',
                'powerAmount' => 1,
                'remainingOffers' => [],
            ],
        );
        $state->round->hasTakenMainAction = true;
        $state->players[1]->resources->coins = 50;
        $state->players[1]->resources->tools = 1;
        $state->players[1]->resources->scholars = 1;

        $rankedActions = app(GameActionRanker::class)->execute(
            $state,
            2,
            depth: 3,
            branchLimit: 2,
            maxNodes: 1,
        );

        $this->assertCount(2, $rankedActions);
        foreach ($rankedActions as $action) {
            $this->assertSame(GameActionOptionType::ResolvePowerOffer, $action->option->type());
            $this->assertSame(50, $action->simulation->state->players[1]->resources->coins);
            $this->assertSame(1, $action->simulation->state->players[1]->resources->tools);
            $this->assertSame(1, $action->simulation->state->players[1]->resources->scholars);
        }
        $this->assertSame(1, $rankedActions[0]->simulation->nextActivePlayerId);
        $this->assertTrue($state->round->hasTakenMainAction);
        $this->assertNotNull($state->pendingInteraction);
    }

    public function test_free_resource_conversions_do_not_gain_an_extra_search_ply(): void
    {
        $state = $this->state();
        $state->turnOrder = [1, 2];
        $state->players[0]->resources = new PlayerResourcesData(
            scholars: 1,
            books: new BookSupplyData(law: 1),
        );
        $state->players[] = new GamePlayerStateData(
            playerId: 2,
            userId: 20,
            color: PlayerColor::Red,
            faction: Faction::Inventors,
            homeland: TerrainType::Wasteland,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(),
        );

        $rankedActions = app(GameActionRanker::class)->execute(
            $state,
            1,
            depth: 2,
            branchLimit: 8,
            maxNodes: 1000,
        );

        $this->assertInstanceOf(SendScholarOptionData::class, $rankedActions[0]->option);
    }

    public function test_it_excludes_auxiliary_actions_when_the_current_turn_budget_is_spent(): void
    {
        $state = $this->state();
        $state->turnOrder = [1, 2];
        $state->players[0]->resources = new PlayerResourcesData(
            tools: 1,
            scholars: 1,
            power: new PowerBowlsStateData(bowlTwo: 2, bowlThree: 3),
            books: new BookSupplyData(law: 1),
        );
        $state->players[] = new GamePlayerStateData(
            playerId: 2,
            userId: 20,
            color: PlayerColor::Red,
            faction: Faction::Inventors,
            homeland: TerrainType::Wasteland,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(),
        );

        $rankedActions = app(GameActionRanker::class)->execute(
            $state,
            1,
            auxiliaryActionsRemaining: 0,
        );

        $rankedTypes = array_map(
            static fn ($rankedAction): GameActionOptionType => $rankedAction->option->type(),
            $rankedActions,
        );

        $this->assertNotContains(GameActionOptionType::ExchangeResources, $rankedTypes);
        $this->assertNotContains(GameActionOptionType::SacrificePower, $rankedTypes);
    }

    public function test_it_rejects_an_invalid_search_budget(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(GameActionRanker::class)->execute($this->state(), 1, maxNodes: 0);
    }

    public function test_it_rejects_an_invalid_time_budget(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(GameActionRanker::class)->execute($this->state(), 1, maxTimeMilliseconds: 0);
    }

    public function test_game_state_deep_copy_is_equal_and_independent(): void
    {
        $state = $this->state();
        $state->players[0]->resources->coins = 3;

        $copy = $state->deepCopy();

        $this->assertNotSame($state, $copy);
        $this->assertNotSame($state->players[0], $copy->players[0]);
        $this->assertNotSame($state->players[0]->resources, $copy->players[0]->resources);
        $this->assertSame($state->toArray(), $copy->toArray());

        $this->assertSame(0, $state->lastDeepCopyNanoseconds());
        $this->assertGreaterThan(0, $copy->lastDeepCopyNanoseconds());
        $this->assertArrayNotHasKey('lastDeepCopyNanoseconds', $copy->toArray());

        $copy->players[0]->resources->coins++;

        $this->assertSame(3, $state->players[0]->resources->coins);
        $this->assertSame(4, $copy->players[0]->resources->coins);
    }

    public function test_deep_copy_isolates_nested_state_and_preserves_shared_dto_references(): void
    {
        $state = $this->townUpgradeState();
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChooseTown,
            1,
            context: ['reward' => ['coins' => 6]],
        );
        $state->pendingInteractionQueue = [$state->pendingInteraction];
        $state->turnStartSnapshot = ['players' => [['coins' => 6]]];
        $state->setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'copy-isolation');
        $state->additional(['metadata' => ['version' => 1]]);

        $copy = $state->deepCopy();

        $this->assertSame($state->toArray(), $copy->toArray());
        $this->assertSame($copy->pendingInteraction, $copy->pendingInteractionQueue[0]);
        $this->assertNotSame($state->pendingInteraction, $copy->pendingInteraction);
        $this->assertNotSame($state->setupPool->planningBundles[0], $copy->setupPool->planningBundles[0]);
        $copy->board->hexes[0]->building->type = BuildingType::Palace;
        $copy->players[0]->resources->books->law = 2;
        $copy->players[0]->resources->power->bowlOne = 3;
        $copy->players[0]->knowledge->law = 4;
        $copy->round->usedSharedActionIds[] = 'test';
        $copy->pendingInteraction->context['reward']['coins'] = 9;
        $copy->turnStartSnapshot['players'][0]['coins'] = 12;
        $copy->additional(['metadata' => ['version' => 2]]);

        $this->assertSame(BuildingType::Guild, $state->board->hexes[0]->building->type);
        $this->assertSame(0, $state->players[0]->resources->books->law);
        $this->assertSame(0, $state->players[0]->resources->power->bowlOne);
        $this->assertSame(0, $state->players[0]->knowledge->law);
        $this->assertSame([], $state->round->usedSharedActionIds);
        $this->assertSame(6, $state->pendingInteraction->context['reward']['coins']);
        $this->assertSame(6, $state->turnStartSnapshot['players'][0]['coins']);
        $this->assertSame(1, $state->toArray()['metadata']['version']);
    }

    public function test_serialized_snapshots_remain_isolated_in_both_directions_after_copying(): void
    {
        $state = $this->townUpgradeState();
        $snapshot = $state->toArray();
        $state->turnStartSnapshot = $snapshot;
        $state->townChoiceCheckpoint = $snapshot;
        $copy = $state->deepCopy();

        $this->assertSame($state->toArray(), $copy->toArray());
        $copy->turnStartSnapshot['players'][0]['resources']['coins'] = 9;
        $copy->townChoiceCheckpoint['board']['hexes'][0]['building']['type'] = BuildingType::Palace->value;
        $state->turnStartSnapshot['round']['number'] = 3;
        $state->townChoiceCheckpoint['players'][0]['resources']['tools'] = 4;

        $this->assertSame(6, $state->turnStartSnapshot['players'][0]['resources']['coins']);
        $this->assertSame(BuildingType::Guild->value, $state->townChoiceCheckpoint['board']['hexes'][0]['building']['type']);
        $this->assertSame(6, $copy->turnStartSnapshot['round']['number']);
        $this->assertSame(2, $copy->townChoiceCheckpoint['players'][0]['resources']['tools']);
    }

    public function test_map_topology_and_mutable_hex_state_remain_isolated_in_both_directions(): void
    {
        $state = $this->townUpgradeState();
        $state->board->riverBankHexIds = ['0:0'];
        $state->board->edgeHexIds = ['1:0'];
        $hex = $state->board->hexes[0];
        $hex->adjacentHexIds = ['1:0'];
        $hex->riverConnectedHexIds = ['2:0'];
        $hex->additional(['metadata' => new PlayerResourcesData(coins: 3)]);
        $copy = $state->deepCopy();

        $this->assertEquals($state->toArray(), $copy->toArray());
        $this->assertNotSame($hex->getAdditionalData()['metadata'], $copy->board->hexes[0]->getAdditionalData()['metadata']);
        $this->assertNotSame($state->board, $copy->board);
        $this->assertNotSame($hex, $copy->board->hexes[0]);
        $this->assertNotSame($hex->building, $copy->board->hexes[0]->building);
        $copy->board->riverBankHexIds[0] = '3:0';
        $copy->board->hexes[0]->adjacentHexIds[0] = '4:0';
        $copy->board->hexes[0]->terrain = TerrainType::Desert;
        $copy->board->hexes[0]->building->type = BuildingType::Palace;
        $state->board->edgeHexIds[0] = '5:0';
        $hex->riverConnectedHexIds[0] = '6:0';
        $hex->getAdditionalData()['metadata']->coins = 9;

        $this->assertSame(['0:0'], $state->board->riverBankHexIds);
        $this->assertSame(['1:0'], $hex->adjacentHexIds);
        $this->assertSame(['1:0'], $copy->board->edgeHexIds);
        $this->assertSame(['2:0'], $copy->board->hexes[0]->riverConnectedHexIds);
        $this->assertNotSame(TerrainType::Desert, $hex->terrain);
        $this->assertSame(BuildingType::Guild, $hex->building->type);
        $this->assertSame(3, $copy->board->hexes[0]->getAdditionalData()['metadata']->coins);
    }

    private function townUpgradeState(): GameStateData
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->number = 6;
        $state->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 2);
        $state->availableTownTileIds = [TownTile::Coins->value, TownTile::Tools->value];
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['b']),
            $this->buildingHex('b', 1, ['a', 'c']),
            $this->buildingHex('c', 1, ['b', 'd']),
            $this->buildingHex('d', 1, ['c']),
        ];
        $state->board->hexes[0]->building->type = BuildingType::Guild;
        $state->board->hexes[1]->building->type = BuildingType::Guild;

        return $state;
    }

    private function state(): GameStateData
    {
        return new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
    }

    /** @param list<string> $adjacentHexIds */
    private function buildingHex(string $id, int $playerId, array $adjacentHexIds): BoardHexStateData
    {
        return new BoardHexStateData(
            id: $id,
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            adjacentHexIds: $adjacentHexIds,
            building: new BuildingStateData(BuildingType::Workshop, $playerId),
        );
    }

    /** @param list<string> $adjacentHexIds */
    private function emptyHex(
        string $id,
        array $adjacentHexIds = [],
        TerrainType $terrain = TerrainType::Forest,
    ): BoardHexStateData {
        return new BoardHexStateData(
            id: $id,
            q: 0,
            r: 0,
            initialTerrain: $terrain,
            terrain: $terrain,
            adjacentHexIds: $adjacentHexIds,
        );
    }
}
