<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Data\StrategicPlanData;
use App\Domain\Automation\Enums\StrategicApproach;
use App\Domain\Automation\Enums\StrategicObjective;
use App\Domain\Automation\Evaluation\Services\GameStateEvaluator;
use App\Domain\Automation\Services\GameActionSelector;
use App\Domain\Automation\Services\StrategicPlanner;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\MakeInnovationOptionData;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Scoring\Enums\FinalRoundScoringTile;
use App\Domain\GameEngine\Setup\Data\GameSetupPoolData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class StrategicPlannerTest extends TestCase
{
    public function test_two_completed_towns_can_be_joined_by_a_multi_house_route_before_round_six(): void
    {
        $state = $this->state();
        $state->round->number = 4;
        $state->availableTownTileIds = [];
        $state->players[0]->resources = new PlayerResourcesData(tools: 2, coins: 4);
        $state->board->hexes = [
            new BoardHexStateData(
                'left',
                0,
                0,
                TerrainType::Forest,
                TerrainType::Forest,
                adjacentHexIds: ['first'],
                building: new BuildingStateData(BuildingType::Workshop, 1),
                townId: 'first-town'
            ),
            new BoardHexStateData('first', 1, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['left', 'second']),
            new BoardHexStateData('second', 2, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['first', 'right']),
            new BoardHexStateData(
                'right',
                3,
                0,
                TerrainType::Forest,
                TerrainType::Forest,
                adjacentHexIds: ['second'],
                building: new BuildingStateData(BuildingType::Workshop, 1),
                townId: 'second-town'
            ),
        ];
        $planner = app(StrategicPlanner::class);
        $plan = $planner->execute($state, 1);
        $this->assertNotNull($plan);
        $this->assertSame(StrategicObjective::ConnectNetwork, $plan->objective);
        $this->assertSame(['first', 'second'], $plan->expansionHexIds);
        $after = $state->deepCopy();
        $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $after->players[0]->resources->tools--;
        $after->players[0]->resources->coins -= 2;
        $this->assertGreaterThan(0, $planner->actionAdjustment($state, $after, 1, $plan));
        $after->players[0]->strategicPlan = $plan;
        $continued = $planner->execute($after, 1);
        $this->assertNotNull($continued);
        $this->assertSame(['second'], $continued->expansionHexIds);
        $evaluator = app(\App\Domain\Automation\Evaluation\Services\BoardPositionProgressEvaluator::class);
        $this->assertGreaterThan(
            $evaluator->execute($state, $after, 1),
            $evaluator->execute($state, $after, 1, $plan)
        );
        $unrelatedRoute = clone $plan;
        $unrelatedRoute->expansionHexIds = ['second'];
        $this->assertSame(
            $evaluator->execute($state, $after, 1),
            $evaluator->execute($state, $after, 1, $unrelatedRoute)
        );
    }

    public function test_two_shipping_levels_can_be_planned_and_paid_for_without_penalizing_the_first_step(): void
    {
        $state = $this->state();
        $state->round->number = 4;
        $state->availableTownTileIds = [];
        $state->players[0]->resources = new PlayerResourcesData(coins: 8, scholars: 2);
        $state->board->hexes = [
            new BoardHexStateData(
                'left',
                0,
                0,
                TerrainType::Forest,
                TerrainType::Forest,
                adjacentHexIds: ['water-one'],
                building: new BuildingStateData(BuildingType::Workshop, 1),
                townId: 'first-town'
            ),
            new BoardHexStateData('water-one', 1, 0, TerrainType::Water, TerrainType::Water, adjacentHexIds: ['left', 'water-two']),
            new BoardHexStateData('water-two', 2, 0, TerrainType::Water, TerrainType::Water, adjacentHexIds: ['water-one', 'right']),
            new BoardHexStateData(
                'right',
                3,
                0,
                TerrainType::Forest,
                TerrainType::Forest,
                adjacentHexIds: ['water-two'],
                building: new BuildingStateData(BuildingType::Workshop, 1),
                townId: 'second-town'
            ),
        ];
        $planner = app(StrategicPlanner::class);
        $plan = $planner->execute($state, 1);
        $this->assertNotNull($plan);
        $this->assertSame(StrategicApproach::Shipping, $plan->approach);
        $this->assertSame(2, $plan->targetLevel);
        $this->assertSame(8, $plan->reservedCoins);
        $this->assertSame(2, $plan->reservedScholars);
        $spentScholar = $state->deepCopy();
        $spentScholar->players[0]->resources->scholars--;
        $this->assertLessThan(-40, $planner->actionAdjustment($state, $spentScholar, 1, $plan));
        $excess = $state->deepCopy();
        $excess->players[0]->resources->scholars++;
        $this->assertSame(0, $planner->actionAdjustment($excess, $state, 1, $plan));
        $replenished = $state->deepCopy();
        $replenished->board->hexes[0]->building->type = BuildingType::School;
        $spentWithIncome = $replenished->deepCopy();
        $spentWithIncome->players[0]->resources->scholars--;
        $this->assertSame(-20, $planner->actionAdjustment($replenished, $spentWithIncome, 1, $plan));
        $replaceable = $state->deepCopy();
        $replaceable->players[0]->resources->power->bowlThree = 5;
        $spentWithPower = $replaceable->deepCopy();
        $spentWithPower->players[0]->resources->scholars--;
        $this->assertSame(-20, $planner->actionAdjustment($replaceable, $spentWithPower, 1, $plan));
        $early = $state->deepCopy();
        $early->round->number = 1;
        $early->players[0]->resources->scholars = 1;
        $early->board->hexes[0]->townId = null;
        $early->board->hexes[3]->townId = null;
        $early->board->hexes[0]->building->type = BuildingType::School;
        $this->assertNull($planner->execute($early, 1));
        $stale = clone $plan;
        $stale->plannedRound = 1;
        $early->players[0]->strategicPlan = $stale;
        $this->assertNull($planner->execute($early, 1));
        $early->players[0]->strategicPlan = null;
        $early->players[0]->resources->scholars = 2;
        $fullyFunded = $planner->execute($early, 1);
        $this->assertNotNull($fullyFunded);
        $this->assertSame(StrategicApproach::Shipping, $fullyFunded->approach);
        $this->assertSame(2, $fullyFunded->targetLevel);
        $joined = $state->deepCopy();
        $joined->players[0]->strategicPlan = $plan;
        $joined->board->bridges = [new \App\Domain\GameEngine\Board\Data\BridgeStateData('left', 'right', 1)];
        $this->assertNull($planner->execute($joined, 1));
        $after = $state->deepCopy();
        $after->players[0]->shippingLevel = 1;
        $after->players[0]->resources->coins -= 4;
        $after->players[0]->resources->scholars--;
        $this->assertSame(16, $planner->actionAdjustment($state, $after, 1, $plan));
        $after->players[0]->strategicPlan = $plan;
        $remaining = $planner->execute($after, 1);
        $this->assertNotNull($remaining);
        $this->assertSame(4, $remaining->reservedCoins);
        $this->assertSame(1, $remaining->reservedScholars);
        $this->assertSame(1, $remaining->remainingActions);
        $finished = $after->deepCopy();
        $finished->players[0]->shippingLevel = 2;
        $finished->players[0]->resources = new PlayerResourcesData();
        $this->assertSame(40, $planner->actionAdjustment($after, $finished, 1, $remaining));
        $finished->players[0]->strategicPlan = $remaining;
        $this->assertNull($planner->execute($finished, 1));
    }

    public function test_funding_progress_rewards_only_resources_missing_from_the_plan(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(coins: 31);
        $plan = new StrategicPlanData(StrategicObjective::FoundTown, [], [], 5, 11, 0, 100);
        $planner = app(StrategicPlanner::class);
        $funded = $state->deepCopy();
        $funded->players[0]->resources->tools = 2;
        $this->assertSame(40, $planner->actionAdjustment($state, $funded, 1, $plan));

        $gold = $state->deepCopy();
        $gold->players[0]->resources->coins += 7;
        $this->assertSame(0, $planner->actionAdjustment($state, $gold, 1, $plan));

        $funded->players[0]->resources->tools = 5;
        $surplus = $funded->deepCopy();
        $surplus->players[0]->resources->tools++;
        $this->assertSame(0, $planner->actionAdjustment($funded, $surplus, 1, $plan));

        $state->players[0]->resources->coins = 10;
        $gold->players[0]->resources->coins = 20;
        $this->assertSame(10, $planner->actionAdjustment($state, $gold, 1, $plan));
    }

    public function test_missing_shipping_scholar_is_valued_during_income_distribution(): void
    {
        $state = $this->state();
        $state->round->phase = GamePhase::Income;
        $plan = new StrategicPlanData(
            StrategicObjective::ConnectNetwork,
            [],
            [],
            0,
            4,
            0,
            100,
            reservedScholars: 1,
            approach: StrategicApproach::Shipping,
            targetLevel: 1
        );
        $after = $state->deepCopy();
        $after->players[0]->resources->scholars = 1;
        $planner = app(StrategicPlanner::class);
        $this->assertSame(20, $planner->actionAdjustment($state, $after, 1, $plan));
        $surplus = $after->deepCopy();
        $surplus->players[0]->resources->scholars++;
        $this->assertSame(0, $planner->actionAdjustment($after, $surplus, 1, $plan));
    }

    public function test_tool_income_finances_a_town_plan_but_not_after_the_last_income_phase(): void
    {
        $state = $this->state();
        $state->round->number = 4;
        $state->players[0]->resources = new PlayerResourcesData(coins: 30);
        $state->board->hexes = [$state->board->hexes[0]];
        $state->board->hexes[0]->building->type = BuildingType::Guild;
        $plan = new StrategicPlanData(StrategicObjective::FoundTown, [], [], 5, 10, 0, 100);
        $after = $state->deepCopy();
        $after->board->hexes[] = new BoardHexStateData(
            'new-workshop',
            1,
            0,
            TerrainType::Forest,
            TerrainType::Forest,
            building: new BuildingStateData(BuildingType::Workshop, 1),
        );
        $planner = app(StrategicPlanner::class);
        $this->assertSame(40, $planner->actionAdjustment($state, $after, 1, $plan));
        $state->round->number = 6;
        $after->round->number = 6;
        $this->assertSame(0, $planner->actionAdjustment($state, $after, 1, $plan));
    }

    public function test_a_valid_intention_survives_other_turns_and_small_changes_in_relative_value(): void
    {
        $state = $this->state();
        $planner = app(StrategicPlanner::class);
        $secondOnly = $state->deepCopy();
        $secondOnly->board->hexes = array_slice($secondOnly->board->hexes, 3);
        $previous = $planner->execute($secondOnly, 1);
        $this->assertNotNull($previous);
        $state->players[0]->strategicPlan = $previous;
        $retained = $planner->execute($state, 1);
        $this->assertNotNull($retained);
        $this->assertSame($previous->anchorHexIds, $retained->anchorHexIds);
        $this->assertSame(2, $retained->reservedTools);
        $this->assertSame(6, $retained->reservedCoins);
        $this->assertSame(1, $retained->requiredAnnexes);
        $this->assertSame(2, $retained->remainingActions);
        $fresh = $planner->execute($state, 1, new StrategicPlanData($previous->objective, ['missing'], [], 0, 0, 0, 0));
        $this->assertNotNull($fresh);
        $this->assertNotSame($retained->anchorHexIds, $fresh->anchorHexIds);
        $this->assertSame($previous, $state->players[0]->strategicPlan);
    }

    public function test_a_completed_or_unfunded_plan_is_discarded(): void
    {
        $state = $this->state();
        $state->board->hexes = array_slice($state->board->hexes, 0, 3);
        $planner = app(StrategicPlanner::class);
        $state->players[0]->strategicPlan = $planner->execute($state, 1);
        $this->assertNotNull($state->players[0]->strategicPlan);
        $state->players[0]->availableAnnexes = 0;
        $this->assertNull($planner->execute($state, 1));
        $state->players[0]->availableAnnexes = 1;
        foreach ($state->board->hexes as $hex) {
            $hex->townId = 'done';
        }
        $this->assertNull($planner->execute($state, 1));
    }

    public function test_a_substantially_better_plan_replaces_the_previous_intention(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 4, coins: 10);
        $state->players[0]->availableAnnexes = 3;
        $state->board->hexes = [...array_slice($state->board->hexes, 0, 3),
            new BoardHexStateData('alone', 4, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['one'], building: new BuildingStateData(BuildingType::Workshop, 1)),
            new BoardHexStateData('one', 5, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['alone', 'two']),
            new BoardHexStateData('two', 6, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['one']),
        ];
        $planner = app(StrategicPlanner::class);
        $previousState = $state->deepCopy();
        $previousState->board->hexes = array_slice($previousState->board->hexes, 3);
        $previous = $planner->execute($previousState, 1);
        $this->assertNotNull($previous);
        $state->players[0]->strategicPlan = $previous;
        $previous->plannedRound = 5;
        $updated = $planner->execute($state, 1);
        $this->assertNotNull($updated);
        $this->assertGreaterThan($previous->score + 80, $updated->score);
        $this->assertSame(['first-0', 'first-1', 'first-2'], $updated->anchorHexIds);
    }

    public function test_an_opponent_claiming_the_target_invalidates_the_intention(): void
    {
        $state = $this->state();
        $state->board->hexes = array_slice($state->board->hexes, 0, 3);
        $state->players[0]->availableAnnexes = 0;
        $state->board->hexes[2]->adjacentHexIds[] = 'target';
        $state->board->hexes[] = new BoardHexStateData('target', 3, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['first-2']);
        $planner = app(StrategicPlanner::class);
        $state->players[0]->strategicPlan = $planner->execute($state, 1);
        $this->assertNotNull($state->players[0]->strategicPlan);
        $this->assertSame(['target'], $state->players[0]->strategicPlan->expansionHexIds);
        $state->board->hexes[3]->building = new BuildingStateData(BuildingType::Workshop, 2);
        $this->assertNull($planner->execute($state, 1));
    }

    public function test_only_actions_that_reduce_remaining_effort_advance_the_intention(): void
    {
        $state = $this->state();
        $state->board->hexes = array_slice($state->board->hexes, 3);
        $planner = app(StrategicPlanner::class);
        $plan = $planner->execute($state, 1);
        $this->assertNotNull($plan);
        $unrelated = $state->deepCopy();
        $unrelated->players[0]->resources = new PlayerResourcesData();
        $this->assertSame(-20, $planner->actionAdjustment($state, $unrelated, 1, $plan));
        $progress = $state->deepCopy();
        $progress->board->hexes[2]->building->type = BuildingType::Guild;
        $progress->players[0]->resources = new PlayerResourcesData();
        $this->assertSame(36, $planner->actionAdjustment($state, $progress, 1, $plan));
        $this->assertSame(BuildingType::Workshop, $state->board->hexes[2]->building->type);

        $completed = $state->deepCopy();
        foreach ($completed->board->hexes as $hex) {
            $hex->townId = 'completed';
        }
        $this->assertSame(40, $planner->actionAdjustment($state, $completed, 1, $plan));
    }

    public function test_plan_serialization_and_simulation_copies_are_isolated(): void
    {
        $state = $this->state();
        $state->players[0]->strategicPlan = app(StrategicPlanner::class)->execute($state, 1);
        $this->assertNotNull($state->players[0]->strategicPlan);
        $restored = GameStateData::from($state->toArray());
        $this->assertSame($state->players[0]->strategicPlan->toArray(), $restored->players[0]->strategicPlan?->toArray());
        $copy = $state->deepCopy();
        $copy->players[0]->strategicPlan->anchorHexIds[] = 'other';
        $this->assertNotContains('other', $state->players[0]->strategicPlan->anchorHexIds);
        $legacy = $state->toArray();
        unset($legacy['players'][0]['strategicPlan']);
        $this->assertNull(GameStateData::from($legacy)->players[0]->strategicPlan);
    }

    public function test_more_building_power_is_not_progress_when_the_plan_still_needs_the_same_annex(): void
    {
        $state = $this->state();
        $state->board->hexes = array_slice($state->board->hexes, 0, 3);
        $state->players[0]->resources = new PlayerResourcesData(tools: 4, coins: 6);
        $planner = app(StrategicPlanner::class);
        $plan = $planner->execute($state, 1);
        $this->assertNotNull($plan);
        $upgraded = $state->deepCopy();
        $upgraded->board->hexes[0]->building->type = BuildingType::Palace;
        $upgraded->players[0]->resources = new PlayerResourcesData();

        $this->assertSame(0, $planner->actionAdjustment($state, $upgraded, 1, $plan));
    }

    public function test_selector_exposes_the_intention_and_its_bounded_action_adjustment(): void
    {
        $state = $this->state();
        $plan = app(StrategicPlanner::class)->execute($state, 1);
        $this->assertNotNull($plan);
        $diagnostics = app(GameActionSelector::class)->selectWithDiagnostics($state, 1, GameBotDifficulty::Fast, strategicPlan: $plan);
        $this->assertNotNull($diagnostics->selected);
        $this->assertSame($plan, $diagnostics->strategicPlan);
        $this->assertSame(36, $diagnostics->selected->scoreBreakdown->strategicPlan);
    }

    public function test_a_high_value_innovation_can_become_the_strategic_intention(): void
    {
        $state = $this->state();
        $state->board->hexes = [];
        $state->availableTownTileIds = [];
        $state->setupPool = new GameSetupPoolData(
            playerCount: 2,
            mapVariant: MapVariant::OneToThreePlayers,
            firstPlayerIndex: 0,
            roundScoringTiles: [],
            additionalFinalRoundGoal: FinalRoundScoringTile::Workshop,
            bookActions: [],
            competencies: [],
            innovations: [Innovation::Professor],
            palaces: [],
            planningBundles: [],
            availableRoundBonuses: [],
            townTiles: [],
        );
        $state->availableInventionIds = [Innovation::Professor->value];
        $state->players[0]->resources->books = new BookSupplyData(banking: 2, law: 2, medicine: 1);

        $plan = app(StrategicPlanner::class)->execute($state, 1);

        $this->assertNotNull($plan);
        $this->assertSame(StrategicObjective::MakeInnovation, $plan->objective);
        $this->assertSame(Innovation::Professor->value, $plan->targetId);
        $this->assertSame(5, $plan->reservedBooks);
        $this->assertNotNull($plan->requiredBooks);
        $selection = app(GameActionSelector::class)->selectWithDiagnostics($state, 1, GameBotDifficulty::Fast, strategicPlan: $plan);
        $this->assertInstanceOf(MakeInnovationOptionData::class, $selection->selected?->option);
        $this->assertSame(40, $selection->selected?->scoreBreakdown->strategicPlan);
        $unfunded = $state->deepCopy();
        $unfunded->players[0]->resources->books = new BookSupplyData(medicine: 5);
        $this->assertNull(app(StrategicPlanner::class)->execute($unfunded, 1));
        $state->players[0]->resources->books = new BookSupplyData(medicine: 1);
        $correct = $state->deepCopy();
        $correct->players[0]->resources->books->banking = 1;
        $wrong = $state->deepCopy();
        $wrong->players[0]->resources->books->medicine++;
        $planner = app(StrategicPlanner::class);
        $this->assertGreaterThan($planner->actionAdjustment($state, $wrong, 1, $plan), $planner->actionAdjustment($state, $correct, 1, $plan));
        $state->round->phase = GamePhase::Income;
        $correct->round->phase = GamePhase::Income;
        $wrong->round->phase = GamePhase::Income;
        $this->assertGreaterThan($planner->actionAdjustment($state, $wrong, 1, $plan), $planner->actionAdjustment($state, $correct, 1, $plan));
        $state->round->phase = GamePhase::Actions;
        $correct->round->phase = GamePhase::Actions;
        $spent = $correct->deepCopy();
        $spent->players[0]->resources->books->banking = 0;
        $this->assertLessThan(0, $planner->actionAdjustment($correct, $spent, 1, $plan));
        $completed = $state->deepCopy();
        $completed->players[0]->inventionIds[] = Innovation::Professor->value;
        $this->assertSame(40, app(StrategicPlanner::class)->actionAdjustment($state, $completed, 1, $plan));
    }

    public function test_a_valuable_palace_can_become_the_strategic_intention(): void
    {
        $state = $this->state();
        $state->round->number = 3;
        $state->availableTownTileIds = [];
        $state->board->hexes = [new BoardHexStateData(
            'guild',
            0,
            0,
            TerrainType::Forest,
            TerrainType::Forest,
            building: new BuildingStateData(BuildingType::Guild, 1),
        )];
        $state->availablePalaceIds = [PalaceAbility::Palace01->value];

        $plan = app(StrategicPlanner::class)->execute($state, 1);

        $this->assertNotNull($plan);
        $this->assertSame(StrategicObjective::BuildPalace, $plan->objective);
        $this->assertSame(PalaceAbility::Palace01->value, $plan->targetId);
        $this->assertSame(4, $plan->reservedTools);
        $completed = $state->deepCopy();
        $completed->board->hexes[0]->building->type = BuildingType::Palace;
        $this->assertSame(40, app(StrategicPlanner::class)->actionAdjustment($state, $completed, 1, $plan));
    }

    public function test_connecting_two_components_can_become_the_strategic_intention(): void
    {
        $state = $this->state();
        $state->availableTownTileIds = [];
        $state->board->hexes = [
            new BoardHexStateData('left', 0, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['link'], building: new BuildingStateData(BuildingType::Workshop, 1)),
            new BoardHexStateData('link', 1, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['left', 'right']),
            new BoardHexStateData('right', 2, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['link'], building: new BuildingStateData(BuildingType::Workshop, 1)),
        ];

        $plan = app(StrategicPlanner::class)->execute($state, 1);

        $this->assertNotNull($plan);
        $this->assertSame(StrategicObjective::ConnectNetwork, $plan->objective);
        $this->assertSame('link', $plan->targetId);
        $this->assertSame(StrategicApproach::Build, $plan->approach);
        $completed = $state->deepCopy();
        $completed->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $this->assertSame(40, app(StrategicPlanner::class)->actionAdjustment($state, $completed, 1, $plan));
    }

    public function test_shipping_is_compared_with_physical_network_connections(): void
    {
        $state = $this->state();
        $state->players[0]->resources->scholars = 1;
        $state->availableTownTileIds = [];
        $state->board->hexes = [
            new BoardHexStateData('left', 0, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['water'], building: new BuildingStateData(BuildingType::Workshop, 1)),
            new BoardHexStateData('water', 1, 0, TerrainType::Water, TerrainType::Water, adjacentHexIds: ['left', 'right']),
            new BoardHexStateData('right', 2, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['water'], building: new BuildingStateData(BuildingType::Workshop, 1)),
        ];

        $plan = app(StrategicPlanner::class)->execute($state, 1);

        $this->assertNotNull($plan);
        $this->assertSame(StrategicObjective::ConnectNetwork, $plan->objective);
        $this->assertSame(StrategicApproach::Shipping, $plan->approach);
        $this->assertSame(1, $plan->targetLevel);
        $this->assertSame(1, $plan->reservedScholars);
        $completed = $state->deepCopy();
        $completed->players[0]->shippingLevel = 1;
        $this->assertSame(40, app(StrategicPlanner::class)->actionAdjustment($state, $completed, 1, $plan));
        $state->players[0]->strategicPlan = $plan;
        $plan->plannedRound = $state->round->number;
        $state->players[0]->resources->scholars = 0;
        $this->assertNull(app(StrategicPlanner::class)->execute($state, 1));
    }

    public function test_early_network_plans_do_not_extend_a_completed_town(): void
    {
        $state = $this->state();
        $state->round->number = 3;
        $state->availableTownTileIds = [];
        $state->board->hexes = [
            new BoardHexStateData('town', 0, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['link'], building: new BuildingStateData(BuildingType::Workshop, 1), townId: 'existing-town'),
            new BoardHexStateData('link', 1, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['town', 'group']),
            new BoardHexStateData('group', 2, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['link'], building: new BuildingStateData(BuildingType::Workshop, 1)),
        ];

        $this->assertNull(app(StrategicPlanner::class)->execute($state, 1));
    }

    public function test_an_early_town_plan_does_not_reserve_an_annex_while_building_space_is_available(): void
    {
        $state = $this->state();
        $state->round->number = 2;
        $state->board->hexes = array_slice($state->board->hexes, 3);
        $state->board->hexes[2]->adjacentHexIds[] = 'expansion';
        $state->board->hexes[] = new BoardHexStateData(
            'expansion',
            3,
            0,
            TerrainType::Forest,
            TerrainType::Forest,
            adjacentHexIds: ['second-2'],
        );

        $this->assertNull(app(StrategicPlanner::class)->execute($state, 1));
    }

    public function test_a_multi_house_route_survives_the_first_build_and_is_invalidated_by_an_opponent(): void
    {
        $state = $this->state();
        $state->players[0]->availableAnnexes = 0;
        $state->players[0]->resources = new PlayerResourcesData(tools: 2, coins: 4);
        $state->board->hexes = [
            new BoardHexStateData('left', 0, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['first'], building: new BuildingStateData(BuildingType::Workshop, 1)),
            new BoardHexStateData('first', 1, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['left', 'second']),
            new BoardHexStateData('second', 2, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['first', 'right']),
            new BoardHexStateData('right', 3, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['second'], building: new BuildingStateData(BuildingType::Workshop, 1)),
        ];
        $planner = app(StrategicPlanner::class);
        $plan = $planner->execute($state, 1);
        $this->assertNotNull($plan);
        $this->assertSame(StrategicObjective::ConnectNetwork, $plan->objective);
        $this->assertSame(['first', 'second'], $plan->expansionHexIds);
        $this->assertSame(2, $plan->remainingActions);

        $after = $state->deepCopy();
        $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $after->players[0]->resources->tools--;
        $after->players[0]->resources->coins -= 2;
        $this->assertGreaterThan(0, $planner->actionAdjustment($state, $after, 1, $plan));
        $after->players[0]->strategicPlan = $plan;
        $continued = $planner->execute($after, 1);
        $this->assertNotNull($continued);
        $this->assertSame(['second'], $continued->expansionHexIds);
        $this->assertSame($plan->anchorHexIds, $continued->anchorHexIds);
        $this->assertSame(1, $continued->reservedTools);
        $this->assertSame(2, $continued->reservedCoins);
        $unfunded = $after->deepCopy();
        $unfunded->players[0]->resources = new PlayerResourcesData();
        $this->assertNull($planner->execute($unfunded, 1));
        $after->players[0]->availableAnnexes = 0;
        $after->board->hexes[2]->building = new BuildingStateData(BuildingType::Workshop, 2);
        $this->assertNull($planner->execute($after, 1));
    }

    public function test_unused_annexes_do_not_have_an_independent_stock_value(): void
    {
        $state = $this->state();
        $state->board->hexes = [];
        $state->players[0]->availableAnnexes = 0;
        $withAnnexes = $state->deepCopy();
        $withAnnexes->players[0]->availableAnnexes = 2;
        $evaluator = app(GameStateEvaluator::class);

        $this->assertSame($evaluator->execute($state, 1), $evaluator->execute($withAnnexes, 1));
    }

    private function state(): GameStateData
    {
        $hexes = [];
        foreach (['first' => [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild],
            'second' => [BuildingType::School, BuildingType::School, BuildingType::Workshop]] as $name => $types) {
            foreach ($types as $index => $type) {
                $neighbors = [];
                if ($index > 0) {
                    $neighbors[] = $name.'-'.($index - 1);
                }
                if ($index < 2) {
                    $neighbors[] = $name.'-'.($index + 1);
                }
                $hexes[] = new BoardHexStateData(
                    $name.'-'.$index,
                    $index,
                    0,
                    TerrainType::Forest,
                    TerrainType::Forest,
                    adjacentHexIds: $neighbors,
                    building: new BuildingStateData($type, 1)
                );
            }
        }

        return new GameStateData(
            players: [new GamePlayerStateData(
                1,
                null,
                PlayerColor::Green,
                Faction::Blessed,
                TerrainType::Forest,
                RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 2, coins: 6),
                availableAnnexes: 1
            )],
            board: new BoardStateData(hexes: $hexes),
            turnOrder: [1],
            round: new RoundStateData(6, GamePhase::Actions),
            availableTownTileIds: [TownTile::Coins->value]
        );
    }
}
