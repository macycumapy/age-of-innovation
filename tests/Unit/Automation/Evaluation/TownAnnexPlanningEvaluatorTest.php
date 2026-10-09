<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\BoardPositionProgressEvaluator;
use App\Domain\Automation\Evaluation\Services\TownAnnexPlanningEvaluator;
use App\Domain\Automation\Services\GameActionRanker;
use App\Domain\Automation\Services\StrategicPlanner;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class TownAnnexPlanningEvaluatorTest extends TestCase
{
    public function test_position_potential_declines_gradually_without_claiming_an_unfunded_town_is_available(): void
    {
        $state = $this->state();
        $state->round->number = 2;
        $group = $this->group('group', [BuildingType::School, BuildingType::School, BuildingType::Workshop]);
        $group[2]->building->hasAnnex = true;
        $state->board->hexes = $group;
        $evaluator = app(TownAnnexPlanningEvaluator::class);
        $scores = [];
        foreach ([2, 1, 0] as $tools) {
            $state->players[0]->resources = new PlayerResourcesData(tools: $tools, coins: 20);
            $scores[] = $evaluator->execute($state, $state->players[0], [$group], [0], includeFutureIncome: false, valueResourceShortfall: true)->score;
        }

        $this->assertSame(80, $scores[0] - $scores[1]);
        $this->assertSame(80, $scores[1] - $scores[2]);
        $this->assertGreaterThan(0, $scores[2]);
        $this->assertSame(0, $evaluator->execute($state, $state->players[0], [$group], [0], includeFutureIncome: false)->projectedTowns);

        $state->players[0]->resources = new PlayerResourcesData(tools: 2, coins: 6);
        $funded = $evaluator->execute($state, $state->players[0], [$group], [0], includeFutureIncome: false, valueResourceShortfall: true);
        $state->players[0]->resources->coins = 5;
        $short = $evaluator->execute($state, $state->players[0], [$group], [0], includeFutureIncome: false, valueResourceShortfall: true);
        $this->assertSame(40, $funded->score - $short->score);

        $state->availableTownTileIds = [];
        $this->assertSame(0, $evaluator->execute($state, $state->players[0], [$group], [0], valueResourceShortfall: true)->score);
    }

    public function test_a_free_palace_market_is_penalized_when_it_has_no_town_or_connection_prospect(): void
    {
        $before = $this->state();
        $before->round->number = 2;
        $before->board->hexes = [...$this->group('source', [BuildingType::Workshop]), ...$this->group('target', [BuildingType::Workshop])];
        $before->board->hexes[1]->building = null;
        $after = $before->deepCopy();
        $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Guild, 1);
        $evaluator = app(BoardPositionProgressEvaluator::class);
        $ordinaryScore = $evaluator->execute($before, $after, 1);
        $before->pendingInteraction = new PendingInteractionData(PendingInteractionType::PlacePalaceGuild, 1, ['target-0']);

        $this->assertSame($ordinaryScore - 80, $evaluator->execute($before, $after, 1));

        $before->round->number = 6;
        $after->round->number = 6;
        $before->pendingInteraction = null;
        $finalRoundScore = $evaluator->execute($before, $after, 1);
        $before->pendingInteraction = new PendingInteractionData(PendingInteractionType::PlacePalaceGuild, 1, ['target-0']);
        $this->assertSame($finalRoundScore, $evaluator->execute($before, $after, 1));
    }

    public function test_a_funded_second_town_does_not_excuse_a_disconnected_palace_market(): void
    {
        $before = $this->state();
        $before->round->number = 2;
        $before->players[0]->resources = new PlayerResourcesData(tools: 12, coins: 24);
        $group = $this->group('target', [BuildingType::Guild, BuildingType::Workshop, BuildingType::Workshop, BuildingType::Workshop]);
        foreach ($group as $hex) {
            $hex->building = null;
        }
        $before->board->hexes = [...$this->group('source', [BuildingType::Workshop]), ...$group];
        $after = $before->deepCopy();
        $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Guild, 1);
        $evaluator = app(BoardPositionProgressEvaluator::class);
        $ordinaryScore = $evaluator->execute($before, $after, 1);
        $before->pendingInteraction = new PendingInteractionData(PendingInteractionType::PlacePalaceGuild, 1, ['target-0']);

        $this->assertSame($ordinaryScore - 80, $evaluator->execute($before, $after, 1));
    }

    public function test_a_free_palace_market_with_a_funded_connection_is_not_stranded(): void
    {
        $before = $this->state();
        $before->round->number = 2;
        $before->availableTownTileIds = [];
        $before->players[0]->resources = new PlayerResourcesData(tools: 1, coins: 2);
        $before->board->hexes = [...$this->group('source', [BuildingType::Workshop]), ...$this->group('target', [BuildingType::Workshop])];
        $before->board->hexes[1]->building = null;
        $before->board->hexes[0]->adjacentHexIds = ['corridor'];
        $before->board->hexes[1]->adjacentHexIds = ['corridor'];
        $before->board->hexes[] = new BoardHexStateData('corridor', 1, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['source-0', 'target-0']);
        $after = $before->deepCopy();
        $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Guild, 1);
        $evaluator = app(BoardPositionProgressEvaluator::class);
        $ordinaryScore = $evaluator->execute($before, $after, 1);
        $before->pendingInteraction = new PendingInteractionData(PendingInteractionType::PlacePalaceGuild, 1, ['target-0']);

        $this->assertSame($ordinaryScore, $evaluator->execute($before, $after, 1));
    }

    public function test_a_palace_market_shipping_connection_requires_the_scholar_to_fund_it(): void
    {
        $before = $this->state();
        $before->round->number = 5;
        $before->availableTownTileIds = [];
        $before->players[0]->resources = new PlayerResourcesData(coins: 4, scholars: 1);
        $before->board->hexes = [...$this->group('source', [BuildingType::Workshop]), ...$this->group('target', [BuildingType::Workshop])];
        $before->board->hexes[1]->building = null;
        $before->board->hexes[0]->adjacentHexIds = ['river'];
        $before->board->hexes[1]->adjacentHexIds = ['river'];
        $before->board->hexes[] = new BoardHexStateData('river', 1, 0, TerrainType::Water, TerrainType::Water, adjacentHexIds: ['source-0', 'target-0']);
        $after = $before->deepCopy();
        $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Guild, 1);
        $evaluator = app(BoardPositionProgressEvaluator::class);
        $ordinaryScore = $evaluator->execute($before, $after, 1);
        $before->pendingInteraction = new PendingInteractionData(PendingInteractionType::PlacePalaceGuild, 1, ['target-0']);
        $this->assertSame($ordinaryScore, $evaluator->execute($before, $after, 1));

        $before->players[0]->resources->scholars = 0;
        $after->players[0]->resources->scholars = 0;
        $before->pendingInteraction = null;
        $ordinaryScore = $evaluator->execute($before, $after, 1);
        $before->pendingInteraction = new PendingInteractionData(PendingInteractionType::PlacePalaceGuild, 1, ['target-0']);
        $this->assertSame($ordinaryScore - 40, $evaluator->execute($before, $after, 1));
    }

    public function test_early_town_planning_prefers_a_neighboring_house_to_an_expensive_university(): void
    {
        $state = $this->state();
        $state->round->number = 2;
        $state->players[0]->resources = new PlayerResourcesData(tools: 20, coins: 40);
        $group = $this->group('group', [BuildingType::School, BuildingType::Guild, BuildingType::Guild]);
        $target = new BoardHexStateData('target', 3, 0, TerrainType::Mountain, TerrainType::Mountain, adjacentHexIds: ['group-2']);
        $group[2]->adjacentHexIds[] = 'target';
        $state->board->hexes = [...$group, $target];

        $plan = app(StrategicPlanner::class)->execute($state, 1);

        $this->assertNotNull($plan);
        $this->assertSame(['target'], $plan->expansionHexIds);
        $this->assertSame(4, $plan->reservedTools);
        $this->assertSame(2, $plan->reservedCoins);

        $target->building = new BuildingStateData(BuildingType::Workshop, 2);
        $compactPlan = app(StrategicPlanner::class)->execute($state, 1);
        $this->assertNotNull($compactPlan);
        $this->assertSame([], $compactPlan->expansionHexIds);
        $this->assertSame(5, $compactPlan->reservedTools);
        $this->assertSame(8, $compactPlan->reservedCoins);
    }

    public function test_equivalent_town_plans_prefer_discounted_markets_even_when_both_are_affordable(): void
    {
        $state = $this->state();
        $state->availableTownTileIds = [TownTile::Coins->value];
        $state->players[0]->resources = new PlayerResourcesData(tools: 20, coins: 50);
        $expensive = $this->group('expensive', [BuildingType::School, BuildingType::School, BuildingType::Workshop, BuildingType::Workshop]);
        $cheap = $this->group('cheap', [BuildingType::School, BuildingType::School, BuildingType::Workshop, BuildingType::Workshop]);
        $opponent = $this->group('opponent', [BuildingType::Workshop])[0];
        $opponent->building->ownerPlayerId = 2;
        $cheap[2]->adjacentHexIds[] = $opponent->id;
        $cheap[3]->adjacentHexIds[] = $opponent->id;
        $state->board->hexes = [...$expensive, ...$cheap, $opponent];
        $planner = app(StrategicPlanner::class);
        $state->board->hexes = $expensive;
        $expensivePlan = $planner->execute($state, 1);
        $state->board->hexes = [...$cheap, $opponent];
        $cheapPlan = $planner->execute($state, 1);
        $state->board->hexes = [...$expensive, ...$cheap, $opponent];
        $jointPlan = $planner->execute($state, 1);

        $this->assertNotNull($expensivePlan);
        $this->assertNotNull($cheapPlan);
        $this->assertNotNull($jointPlan);
        $this->assertGreaterThan($expensivePlan->score, $cheapPlan->score);
        $this->assertSame($cheapPlan->reservedCoins, $jointPlan->reservedCoins);
        $this->assertSame($cheapPlan->score, $jointPlan->score);
        $this->assertSame($cheapPlan->anchorHexIds, $jointPlan->anchorHexIds);
    }

    public function test_only_annexes_from_the_selected_jointly_funded_town_plan_are_useful(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 2, coins: 6);
        $groups = [];
        foreach (['first', 'second'] as $name) {
            $group = $this->group($name, [BuildingType::School, BuildingType::School, BuildingType::Workshop]);
            $group[0]->building->hasAnnex = true;
            $groups[] = $group;
        }
        $state->board->hexes = [...$groups[0], ...$groups[1]];
        $plan = app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], $groups, [0, 0]);

        $this->assertSame(1, $plan->projectedTowns);
        $this->assertCount(1, $plan->usefulAnnexHexIds);
        $this->assertContains($plan->usefulAnnexHexIds[0], ['first-0', 'second-0']);
        $this->assertSame(2, $plan->reservedTools);
        $this->assertSame(6, $plan->reservedCoins);
    }

    public function test_starting_houses_prefer_separate_groups_with_a_short_connection(): void
    {
        $before = $this->state();
        $before->round->phase = GamePhase::Setup;
        $before->board->hexes = $this->group('line', array_fill(0, 7, BuildingType::Workshop));
        foreach ($before->board->hexes as $index => $hex) {
            if ($index !== 0) {
                $hex->building = null;
            }
        }
        $adjacent = $before->deepCopy();
        $adjacent->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $separate = $before->deepCopy();
        $separate->board->hexes[3]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $overlapping = $before->deepCopy();
        $overlapping->board->hexes[2]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $distant = $before->deepCopy();
        $distant->board->hexes[6]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $evaluator = app(BoardPositionProgressEvaluator::class);
        $adjacentSetupScore = $evaluator->execute($before, $adjacent, 1);
        $this->assertGreaterThan($evaluator->execute($before, $adjacent, 1), $evaluator->execute($before, $separate, 1));
        $this->assertGreaterThan($evaluator->execute($before, $overlapping, 1), $evaluator->execute($before, $separate, 1));
        $this->assertGreaterThan($evaluator->execute($before, $distant, 1), $evaluator->execute($before, $separate, 1));

        $before->round->phase = GamePhase::Actions;
        $adjacent->round->phase = GamePhase::Actions;
        $separate->round->phase = GamePhase::Actions;
        $this->assertGreaterThan($adjacentSetupScore, $evaluator->execute($before, $adjacent, 1));
        $this->assertNull($before->board->hexes[1]->building);
    }

    public function test_completed_town_development_has_a_larger_penalty_before_the_last_round(): void
    {
        $evaluator = app(BoardPositionProgressEvaluator::class);
        foreach (['house', 'palace'] as $development) {
            $before = $this->state();
            $before->board->hexes = $this->group('town', [BuildingType::Guild]);
            $before->board->hexes[0]->townId = 'existing';
            $before->board->hexes[0]->adjacentHexIds[] = 'target';
            $before->board->hexes[] = new BoardHexStateData('target', 1, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['town-0']);
            $after = $before->deepCopy();
            if ($development === 'house') {
                $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 1);
            } else {
                $after->board->hexes[0]->building->type = BuildingType::Palace;
            }
            $lastRoundScore = $evaluator->execute($before, $after, 1);
            foreach (range(1, 5) as $round) {
                $before->round->number = $round;
                $after->round->number = $round;
                $this->assertLessThanOrEqual($lastRoundScore - ($development === 'house' ? 90 : 120), $evaluator->execute($before, $after, 1), $development.' round '.$round);
            }
            $this->assertSame(BuildingType::Guild, $before->board->hexes[0]->building->type);
            $this->assertNull($before->board->hexes[1]->building);
        }
    }

    public function test_a_funded_neighboring_house_is_preferred_to_spending_an_annex_for_the_same_town(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(tools: 1, coins: 2);
        $state->players[0]->availableAnnexes = 1;
        $state->board->hexes = $this->group('group', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        $state->board->hexes[2]->adjacentHexIds[] = 'target';
        $state->board->hexes[] = new BoardHexStateData('target', 3, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['group-2']);

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);

        $this->assertSame(GameActionOptionType::BuildWorkshop, $ranked[0]->option->type());
        $this->assertSame('target', $ranked[0]->option->hexId);
        $this->assertSame(1, $ranked[0]->simulation->state->players[0]->availableAnnexes);

        $state->board->hexes[3]->building = new BuildingStateData(BuildingType::Workshop, 2);
        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);
        $this->assertSame(GameActionOptionType::PlaceAnnex, $ranked[0]->option->type());
    }

    public function test_an_annex_keeps_its_value_when_tools_or_workshops_are_missing(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->resources = new PlayerResourcesData(coins: 2);
        $state->players[0]->availableAnnexes = 1;
        $state->board->hexes = $this->group('group', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        $state->board->hexes[2]->adjacentHexIds[] = 'target';
        $state->board->hexes[] = new BoardHexStateData('target', 3, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['group-2']);
        $ranker = app(GameActionRanker::class);
        $this->assertSame(GameActionOptionType::PlaceAnnex, $ranker->execute($state, 1, depth: 1)[0]->option->type());
        $state->players[0]->resources->tools = 1;
        $state->players[0]->resources->coins = 0;
        $this->assertSame(GameActionOptionType::PlaceAnnex, $ranker->execute($state, 1, depth: 1)[0]->option->type());
        $state->players[0]->resources->coins = 2;
        for ($index = 0; $index < BuildingType::Workshop->supplyLimit(); $index++) {
            $state->board->hexes = [...$state->board->hexes, ...$this->group('used-'.$index, [BuildingType::Workshop])];
        }
        $selected = $ranker->execute($state, 1, depth: 1)[0];
        $this->assertSame(GameActionOptionType::PlaceAnnex, $selected->option->type());
        $this->assertStringStartsWith('group-', $selected->option->hexId);
    }

    public function test_future_income_is_not_an_immediately_funded_alternative_to_an_annex(): void
    {
        $state = $this->state();
        $state->round->number = 5;
        $state->players[0]->resources = new PlayerResourcesData(coins: 2);
        $group = $this->group('group', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        $group[2]->adjacentHexIds[] = 'target';
        $state->board->hexes = [...$group, new BoardHexStateData('target', 3, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['group-2'])];
        $evaluator = app(TownAnnexPlanningEvaluator::class);
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$group], [216])->projectedTowns);
        $this->assertSame(0, $evaluator->execute($state, $state->players[0], [$group], [216], includeFutureIncome: false)->projectedTowns);
    }

    public function test_an_ordinary_group_can_complete_a_second_town_with_two_houses_and_no_annexes(): void
    {
        $state = $this->twoTownExpansionState();
        $group = array_slice($state->board->hexes, 0, 3);
        $planning = app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [$group], [144]);

        $this->assertSame(2, $planning->projectedTowns);
        $this->assertSame(2, $planning->reservedTools);
        $this->assertSame(4, $planning->reservedCoins);
        $this->assertSame(0, $state->players[0]->availableAnnexes);
        $this->assertNull($state->board->hexes[6]->building);
    }

    public function test_spending_the_second_town_budget_on_an_unrelated_house_loses_the_forecast(): void
    {
        $before = $this->twoTownExpansionState();
        $after = $before->deepCopy();
        $after->players[0]->resources->tools--;
        $after->players[0]->resources->coins -= 2;
        $after->board->hexes[] = $this->group('unrelated', [BuildingType::Workshop])[0];
        $planning = app(TownAnnexPlanningEvaluator::class)->execute($after, $after->players[0], [array_slice($after->board->hexes, 0, 3)], [144]);

        $this->assertSame(1, $planning->projectedTowns);
        $this->assertLessThan(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_the_ranker_builds_towards_the_second_town_instead_of_extending_the_old_one(): void
    {
        $state = $this->twoTownExpansionState();
        $state->turnOrder = [1];
        $state->board->hexes[5]->adjacentHexIds[] = 'old-extension';
        $state->board->hexes[] = new BoardHexStateData('old-extension', 0, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['old-2']);

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);

        $this->assertSame(GameActionOptionType::BuildWorkshop, $ranked[0]->option->type());
        $this->assertSame('target-one', $ranked[0]->option->hexId);
    }

    public function test_a_blocked_or_expensive_continuation_does_not_forecast_the_second_town(): void
    {
        $state = $this->twoTownExpansionState();
        $group = array_slice($state->board->hexes, 0, 3);
        $evaluator = app(TownAnnexPlanningEvaluator::class);
        $state->board->hexes[7]->building = new BuildingStateData(BuildingType::Workshop, 2);
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$group], [144])->projectedTowns);
        $state->board->hexes[7]->building = null;
        $state->board->hexes[7]->terrain = TerrainType::Mountain;
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$group], [144])->projectedTowns);
    }

    public function test_two_forecast_towns_cannot_merge_their_future_houses_directly_or_through_a_bridge(): void
    {
        $state = $this->twoTownExpansionState();
        $first = array_slice($state->board->hexes, 0, 3);
        $second = $this->group('second', [BuildingType::Guild, BuildingType::School, BuildingType::Workshop]);
        $second[2]->adjacentHexIds[] = 'second-target-one';
        $state->board->hexes = [...$first, ...array_slice($state->board->hexes, 6), ...$second,
            new BoardHexStateData('second-target-one', 5, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['second-2', 'second-target-two']),
            new BoardHexStateData('second-target-two', 6, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['second-target-one']),
        ];
        $state->players[0]->resources = new PlayerResourcesData(tools: 4, coins: 8);
        $evaluator = app(TownAnnexPlanningEvaluator::class);
        $this->assertSame(2, $evaluator->execute($state, $state->players[0], [$first, $second], [144, 144])->projectedTowns);

        $state->board->hexes[4]->adjacentHexIds[] = 'second-target-two';
        $state->board->hexes[9]->adjacentHexIds[] = 'target-two';
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$first, $second], [144, 144])->projectedTowns);

        array_pop($state->board->hexes[4]->adjacentHexIds);
        array_pop($state->board->hexes[9]->adjacentHexIds);
        $state->board->bridges = [new BridgeStateData('target-two', 'second-target-two', 1)];
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$first, $second], [144, 144])->projectedTowns);
        $state->board->bridges[0]->ownerPlayerId = 2;
        $this->assertSame(2, $evaluator->execute($state, $state->players[0], [$first, $second], [144, 144])->projectedTowns);
    }

    public function test_forecast_houses_require_remaining_workshop_supply(): void
    {
        $state = $this->twoTownExpansionState();
        $group = array_slice($state->board->hexes, 0, 3);
        $state->board->hexes = [...$state->board->hexes,
            ...$this->group('elsewhere', array_fill(0, BuildingType::Workshop->supplyLimit() - 3, BuildingType::Workshop)),
        ];
        $evaluator = app(TownAnnexPlanningEvaluator::class);
        $this->assertSame(2, $evaluator->execute($state, $state->players[0], [$group], [144])->projectedTowns);

        $state->board->hexes = [...$state->board->hexes, ...$this->group('last', [BuildingType::Workshop])];
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$group], [144])->projectedTowns);
    }

    private function twoTownExpansionState(): GameStateData
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 2, coins: 4);
        $group = $this->group('group', [BuildingType::Guild, BuildingType::School, BuildingType::Workshop]);
        $group[2]->adjacentHexIds[] = 'target-one';
        $town = $this->group('old', [BuildingType::University, BuildingType::Guild, BuildingType::Guild]);
        foreach ($town as $hex) {
            $hex->townId = 'old-town';
        }
        $state->board->hexes = [...$group, ...$town,
            new BoardHexStateData('target-one', 3, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['group-2', 'target-two']),
            new BoardHexStateData('target-two', 4, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['target-one']),
        ];

        return $state;
    }

    public function test_a_more_expensive_first_cell_can_lead_to_an_affordable_second_town(): void
    {
        $state = $this->twoTownExpansionState();
        $state->players[0]->resources = new PlayerResourcesData(tools: 5, coins: 4);
        $state->board->hexes[6]->terrain = TerrainType::Mountain;
        $state->board->hexes[7]->terrain = TerrainType::Mountain;
        $state->board->hexes[2]->adjacentHexIds[] = 'alternative';
        $state->board->hexes[] = new BoardHexStateData('alternative', 3, 1, TerrainType::Mountain, TerrainType::Mountain, adjacentHexIds: ['group-2', 'continuation']);
        $state->board->hexes[] = new BoardHexStateData('continuation', 4, 1, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['alternative']);
        $planning = app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [array_slice($state->board->hexes, 0, 3)], [144]);

        $this->assertSame(2, $planning->projectedTowns);
        $this->assertEqualsCanonicalizing(['alternative', 'continuation'], $planning->expansionHexIds);
        $this->assertSame(5, $planning->reservedTools);
    }

    public function test_a_town_chain_budgets_terraforming_a_house_and_a_building_upgrade_together(): void
    {
        $state = $this->twoTownExpansionState();
        $state->players[0]->resources = new PlayerResourcesData(tools: 6, coins: 8);
        $state->board->hexes[6]->terrain = TerrainType::Mountain;
        $state->board->hexes[7]->building = new BuildingStateData(BuildingType::Workshop, 2);
        $planning = app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [array_slice($state->board->hexes, 0, 3)], [144]);

        $this->assertSame(2, $planning->projectedTowns);
        $this->assertSame(6, $planning->reservedTools);
        $this->assertSame(5, $planning->reservedCoins);
        $this->assertSame(['target-one'], $planning->expansionHexIds);
        $state->players[0]->resources->tools--;
        $this->assertSame(1, app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [array_slice($state->board->hexes, 0, 3)], [144])->projectedTowns);
    }

    public function test_a_contested_city_cell_has_urgency_only_when_its_loss_breaks_the_town_plan(): void
    {
        $state = $this->twoTownExpansionState();
        $group = array_slice($state->board->hexes, 0, 3);
        $opponent = clone $state->players[0];
        $opponent->playerId = 2;
        $opponent->resources = new PlayerResourcesData(tools: 1, coins: 2);
        $state->players[] = $opponent;
        $state->board->hexes[6]->adjacentHexIds[] = 'opponent';
        $state->board->hexes[] = new BoardHexStateData('opponent', 3, 1, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['target-one'], building: new BuildingStateData(BuildingType::Workshop, 2));
        $planning = app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [$group], [144]);
        $risk = app(\App\Domain\Automation\Evaluation\Services\NetworkBlockingRiskEvaluator::class);
        $this->assertSame(80, $risk->townRisk($state, $state->players[0], [$group], [144], $planning));
        $this->assertNull($state->board->hexes[6]->building);
        $this->assertSame(TerrainType::Forest, $state->board->hexes[6]->terrain);

        $state->passedPlayerIds = [2];
        $this->assertSame(0, $risk->townRisk($state, $state->players[0], [$group], [144], $planning));
        $state->passedPlayerIds = [];
        $state->players[1]->resources->tools = 0;
        $this->assertSame(0, $risk->townRisk($state, $state->players[0], [$group], [144], $planning));
        $state->players[1]->resources->tools = 1;
        $state->board->hexes[2]->adjacentHexIds[] = 'alternative';
        $state->board->hexes[] = new BoardHexStateData('alternative', 4, 1, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['group-2', 'continuation']);
        $state->board->hexes[] = new BoardHexStateData('continuation', 5, 1, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['alternative']);
        $this->assertSame(0, $risk->townRisk($state, $state->players[0], [$group], [144], $planning));
    }

    public function test_distant_starting_connections_do_not_share_a_capped_penalty(): void
    {
        $before = $this->state();
        $before->round->phase = GamePhase::Setup;
        $before->board->hexes = $this->group('line', array_fill(0, 15, BuildingType::Workshop));
        foreach ($before->board->hexes as $index => $hex) {
            $hex->building = $index === 0 ? new BuildingStateData(BuildingType::Workshop, 1) : null;
        }
        $near = $before->deepCopy();
        $near->board->hexes[9]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $far = $before->deepCopy();
        $far->board->hexes[13]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $evaluator = app(BoardPositionProgressEvaluator::class);

        $this->assertGreaterThan($evaluator->execute($before, $far, 1), $evaluator->execute($before, $near, 1));
    }

    public function test_a_bridge_to_an_unaffordable_empty_foreign_hex_has_negative_value(): void
    {
        $before = $this->state();
        $before->players[0]->resources = new PlayerResourcesData(tools: 1, coins: 2);
        $before->board->hexes = [...$this->group('source', [BuildingType::Workshop]), ...$this->group('target', [BuildingType::Workshop])];
        $before->board->hexes[1]->building = null;
        $before->board->hexes[1]->terrain = TerrainType::Mountain;
        $after = $before->deepCopy();
        $after->board->bridges[] = new BridgeStateData('source-0', 'target-0', 1);

        $this->assertLessThan(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_a_funded_bridge_to_an_empty_homeland_hex_keeps_expansion_value(): void
    {
        $before = $this->state();
        $before->players[0]->resources = new PlayerResourcesData(tools: 1, coins: 2);
        $before->board->hexes = [...$this->group('source', [BuildingType::Workshop]), ...$this->group('target', [BuildingType::Workshop])];
        $before->board->hexes[1]->building = null;
        $after = $before->deepCopy();
        $after->board->bridges[] = new BridgeStateData('source-0', 'target-0', 1);

        $this->assertGreaterThan(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_late_isolated_construction_without_network_growth_has_negative_value(): void
    {
        $before = $this->state();
        $before->players[0]->resources = new PlayerResourcesData(tools: 1, coins: 2);
        $before->board->hexes = [...$this->group('source', [BuildingType::Workshop]), ...$this->group('target', [BuildingType::Workshop])];
        $before->board->hexes[1]->building = null;
        $after = $before->deepCopy();
        $after->players[0]->resources = new PlayerResourcesData();
        $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 1);

        $this->assertLessThan(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_late_construction_connecting_through_an_existing_bridge_is_not_isolated(): void
    {
        $before = $this->state();
        $before->players[0]->resources = new PlayerResourcesData(tools: 1, coins: 2);
        $before->board->hexes = [...$this->group('source', [BuildingType::Workshop]), ...$this->group('target', [BuildingType::Workshop])];
        $before->board->hexes[1]->building = null;
        $before->board->bridges[] = new BridgeStateData('source-0', 'target-0', 1);
        $after = $before->deepCopy();
        $after->players[0]->resources = new PlayerResourcesData();
        $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 1);

        $this->assertGreaterThan(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_free_isolated_workshop_rewards_are_not_penalized_as_paid_construction(): void
    {
        $before = $this->state();
        $before->board->hexes = [...$this->group('source', [BuildingType::Workshop]), ...$this->group('target', [BuildingType::Workshop])];
        $before->board->hexes[1]->building = null;
        $after = $before->deepCopy();
        $after->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 1);

        $this->assertGreaterThanOrEqual(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_a_bridge_inside_an_already_connected_group_has_negative_position_value(): void
    {
        $before = $this->state();
        $before->board->hexes = $this->group('town', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        foreach ($before->board->hexes as $hex) {
            $hex->townId = 'town';
        }
        $after = $before->deepCopy();
        $after->board->bridges[] = new BridgeStateData('town-0', 'town-2', 1);

        $this->assertSame(-15, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_a_bridge_joining_separate_groups_is_not_penalized_as_redundant(): void
    {
        $before = $this->state();
        $before->board->hexes = [...$this->group('first', [BuildingType::Guild]), ...$this->group('second', [BuildingType::Guild])];
        $after = $before->deepCopy();
        $after->board->bridges[] = new BridgeStateData('first-0', 'second-0', 1);

        $this->assertGreaterThan(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_building_against_a_completed_town_is_worse_than_the_same_unfinished_group(): void
    {
        $before = $this->state();
        $group = $this->group('group', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild, BuildingType::Workshop]);
        $before->board->hexes = array_slice($group, 0, 3);
        foreach ($before->board->hexes as $hex) {
            $hex->townId = 'town';
        }
        $after = $before->deepCopy();
        $after->board->hexes[] = $group[3];
        $evaluator = app(BoardPositionProgressEvaluator::class);
        $completedScore = $evaluator->execute($before, $after, 1);
        foreach ([$before, $after] as $state) {
            foreach ($state->board->hexes as $hex) {
                $hex->townId = null;
            }
        }

        $this->assertLessThan($evaluator->execute($before, $after, 1), $completedScore);
    }

    public function test_starting_houses_prefer_a_shorter_buildable_connection(): void
    {
        $before = $this->state();
        $before->round->phase = GamePhase::Setup;
        $group = $this->group('line', array_fill(0, 7, BuildingType::Workshop));
        foreach ($group as $index => $hex) {
            $hex->building = $index === 0 ? new BuildingStateData(BuildingType::Workshop, 1) : null;
        }
        $before->board->hexes = $group;
        $near = $before->deepCopy();
        $near->board->hexes[3]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $far = $before->deepCopy();
        $far->board->hexes[6]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $evaluator = app(BoardPositionProgressEvaluator::class);

        $this->assertGreaterThan($evaluator->execute($before, $far, 1), $evaluator->execute($before, $near, 1));
    }

    public function test_starting_connection_forecast_avoids_an_opponent_blocked_corridor(): void
    {
        $before = $this->state();
        $before->round->phase = GamePhase::Setup;
        $before->board->hexes = $this->group('line', array_fill(0, 5, BuildingType::Workshop));
        foreach ($before->board->hexes as $index => $hex) {
            $hex->building = $index === 0 ? new BuildingStateData(BuildingType::Workshop, 1) : null;
        }
        $before->board->hexes[3]->building = new BuildingStateData(BuildingType::Workshop, 2);
        $near = $before->deepCopy();
        $near->board->hexes[2]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $blocked = $before->deepCopy();
        $blocked->board->hexes[4]->building = new BuildingStateData(BuildingType::Workshop, 1);
        $evaluator = app(BoardPositionProgressEvaluator::class);

        $this->assertGreaterThan($evaluator->execute($before, $blocked, 1), $evaluator->execute($before, $near, 1));
    }

    public function test_an_annex_inside_a_completed_town_does_not_delay_the_final_pass(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->players[0]->availableAnnexes = 1;
        $state->board->hexes = $this->group('town', [BuildingType::Palace, BuildingType::Guild, BuildingType::Guild, BuildingType::Workshop]);
        foreach ($state->board->hexes as $hex) {
            $hex->townId = 'town';
        }

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 2);

        $this->assertSame(GameActionOptionType::Pass, $ranked[0]->option->type());
    }

    public function test_an_annex_on_an_isolated_workshop_has_no_town_progress_value(): void
    {
        $before = $this->state();
        $before->players[0]->availableAnnexes = 1;
        $before->board->hexes = $this->group('alone', [BuildingType::Workshop]);
        $after = $before->deepCopy();
        $after->players[0]->availableAnnexes = 0;
        $after->board->hexes[0]->building->hasAnnex = true;

        $planning = app(TownAnnexPlanningEvaluator::class)->execute($after, $after->players[0], [$after->board->hexes], [0]);

        $this->assertSame(0, $planning->projectedTowns);
        $this->assertSame([], $planning->usefulAnnexHexIds);
        $this->assertSame(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_one_available_annex_cannot_be_used_for_two_groups(): void
    {
        $state = $this->state();
        $state->players[0]->availableAnnexes = 1;
        $first = $this->group('first', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        $second = $this->group('second', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        $state->board->hexes = [...$first, ...$second];
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$first, $second], [216, 216])->projectedTowns);
        $state->players[0]->availableAnnexes = 2;
        $this->assertSame(2, $evaluator->execute($state, $state->players[0], [$first, $second], [216, 216])->projectedTowns);
    }

    public function test_saving_the_second_annex_keeps_two_towns_reachable_after_upgrades(): void
    {
        $before = $this->state();
        $before->round->number = 3;
        $before->players[0]->resources = new PlayerResourcesData(tools: 11, coins: 26);
        $before->players[0]->availableAnnexes = 1;
        $first = $this->group('first', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $first[2]->building->hasAnnex = true;
        $second = $this->group('second', [BuildingType::Workshop, BuildingType::Workshop, BuildingType::Workshop]);
        $before->board->hexes = [...$first, ...$second];
        $evaluator = app(TownAnnexPlanningEvaluator::class);
        $plan = $evaluator->execute($before, $before->players[0], [$first, $second], [288, 48]);

        $this->assertSame(2, $plan->projectedTowns);
        $after = $before->deepCopy();
        $after->players[0]->availableAnnexes = 0;
        $after->board->hexes[0]->building->hasAnnex = true;
        $placed = $evaluator->execute($after, $after->players[0], [array_slice($after->board->hexes, 0, 3), array_slice($after->board->hexes, 3)], [392, 48]);
        $this->assertSame(1, $placed->projectedTowns);
        $this->assertLessThan(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
        $this->assertSame(1, $before->players[0]->availableAnnexes);
        $this->assertFalse($before->board->hexes[0]->building->hasAnnex);
    }

    public function test_the_same_university_supply_and_resources_are_not_forecast_twice(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 10, coins: 16);
        $first = $this->group('first', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $second = $this->group('second', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $first[2]->building->hasAnnex = true;
        $second[2]->building->hasAnnex = true;
        $first[2]->building->isNeutral = true;
        $second[2]->building->isNeutral = true;
        $state->board->hexes = [...$first, ...$second, ...$this->group('palace', [BuildingType::Palace])];

        $this->assertSame(1, app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [$first, $second], [288, 288])->projectedTowns);
    }

    public function test_an_unaffordable_upgrade_does_not_give_an_annex_future_town_value(): void
    {
        $state = $this->state();
        $group = $this->group('group', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $group[2]->building->hasAnnex = true;
        $state->board->hexes = $group;
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $this->assertSame(0, $evaluator->execute($state, $state->players[0], [$group], [288])->projectedTowns);
        $state->players[0]->resources = new PlayerResourcesData(tools: 5, coins: 8);
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$group], [288])->projectedTowns);
    }

    public function test_the_ranker_allocates_the_second_annex_to_the_other_group(): void
    {
        $state = $this->state();
        $state->round->number = 5;
        $state->turnOrder = [1];
        $state->availableTownTileIds = [TownTile::Coins->value, TownTile::Tools->value];
        $state->players[0]->resources = new PlayerResourcesData(tools: 11, coins: 26);
        $state->players[0]->availableAnnexes = 1;
        $first = $this->group('first', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $first[2]->building->hasAnnex = true;
        $second = $this->group('second', [BuildingType::Workshop, BuildingType::Workshop, BuildingType::Workshop]);
        $state->board->hexes = [...$first, ...$second];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);

        $this->assertSame(GameActionOptionType::PlaceAnnex, $ranked[0]->option->type());
        $this->assertStringStartsWith('second-', $ranked[0]->option->hexId);
        $after = $ranked[0]->simulation->state;
        $planning = app(TownAnnexPlanningEvaluator::class)->execute(
            $after,
            $after->players[0],
            [array_slice($after->board->hexes, 0, 3), array_slice($after->board->hexes, 3)],
            [288, 128],
        );
        $this->assertSame(2, $planning->projectedTowns);
        $this->assertSame(1, $state->players[0]->availableAnnexes);
    }

    public function test_neutral_buildings_are_not_upgraded_in_the_forecast(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 30, coins: 60);
        $state->players[0]->availableAnnexes = 1;
        $group = $this->group('group', [BuildingType::Workshop, BuildingType::Workshop, BuildingType::Workshop]);
        foreach ($group as $hex) {
            $hex->building->isNeutral = true;
        }
        $state->board->hexes = $group;

        $this->assertSame(0, app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [$group], [48])->projectedTowns);
    }

    public function test_the_second_group_can_be_completed_by_one_adjacent_workshop_and_upgrades(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 12, coins: 28);
        $state->players[0]->availableAnnexes = 1;
        $first = $this->group('first', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $first[2]->building->hasAnnex = true;
        $second = $this->group('second', [BuildingType::Workshop, BuildingType::Workshop]);
        $second[1]->adjacentHexIds[] = 'target';
        $target = new BoardHexStateData('target', 3, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['second-1']);
        $state->board->hexes = [...$first, ...$second, $target];
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $this->assertSame(2, $evaluator->execute($state, $state->players[0], [$first, $second], [288, 16])->projectedTowns);
        $target->building = new BuildingStateData(BuildingType::Workshop, 2);
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$first, $second], [288, 16])->projectedTowns);
    }

    public function test_forecast_workshops_include_terraforming_cost_and_supply_limits(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 7, coins: 20);
        $state->players[0]->availableAnnexes = 1;
        $group = $this->group('group', [BuildingType::Workshop, BuildingType::Workshop]);
        $group[1]->adjacentHexIds[] = 'target';
        $target = new BoardHexStateData('target', 2, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['group-1']);
        $state->board->hexes = [...$group, $target];
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$group], [16])->projectedTowns);
        $target->terrain = TerrainType::Mountain;
        $this->assertSame(0, $evaluator->execute($state, $state->players[0], [$group], [16])->projectedTowns);
        $target->terrain = TerrainType::Forest;
        for ($index = 0; $index < 7; $index++) {
            $state->board->hexes[] = $this->group('other-'.$index, [BuildingType::Workshop])[0];
        }
        $this->assertSame(0, $evaluator->execute($state, $state->players[0], [$group], [16])->projectedTowns);
    }

    public function test_forecast_expansion_cannot_join_an_existing_town(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 20, coins: 40);
        $state->players[0]->availableAnnexes = 1;
        $group = $this->group('group', [BuildingType::Workshop, BuildingType::Workshop]);
        $group[1]->adjacentHexIds[] = 'target';
        $target = new BoardHexStateData('target', 2, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['group-1', 'old-0']);
        $town = $this->group('old', [BuildingType::Palace]);
        $town[0]->townId = 'old-town';
        $state->board->hexes = [...$group, $target, ...$town];

        $this->assertSame(1, app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [$group], [16])->projectedTowns);
    }

    public function test_upgrade_costs_share_one_resource_budget_across_groups(): void
    {
        $state = $this->state();
        $first = $this->group('first', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $second = $this->group('second', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $first[2]->building->hasAnnex = true;
        $first[2]->building->isNeutral = true;
        $second[2]->building->hasAnnex = true;
        $state->board->hexes = [...$first, ...$second];
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $state->players[0]->resources = new PlayerResourcesData(tools: 5, coins: 8);
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$first, $second], [288, 288])->projectedTowns);
        $state->players[0]->resources = new PlayerResourcesData(tools: 7, coins: 14);
        $this->assertSame(2, $evaluator->execute($state, $state->players[0], [$first, $second], [288, 288])->projectedTowns);
    }

    public function test_town_forecasts_respect_the_remaining_town_tile_supply(): void
    {
        $state = $this->state();
        $state->players[0]->availableAnnexes = 2;
        $first = $this->group('first', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        $second = $this->group('second', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        $state->board->hexes = [...$first, ...$second];
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $state->availableTownTileIds = [TownTile::Coins->value];
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$first, $second], [216, 216])->projectedTowns);
        $state->availableTownTileIds = [];
        $this->assertSame(0, $evaluator->execute($state, $state->players[0], [$first, $second], [216, 216])->projectedTowns);
    }

    public function test_a_six_power_town_preserves_its_forecast_value_when_founded(): void
    {
        $before = $this->state();
        $before->players[0]->palaceId = PalaceAbility::Palace08->value;
        $group = $this->group('group', [BuildingType::Guild, BuildingType::Guild]);
        foreach ($group as $hex) {
            $hex->building->hasAnnex = true;
        }
        $before->board->hexes = $group;
        $after = $before->deepCopy();
        array_pop($after->availableTownTileIds);
        foreach ($after->board->hexes as $hex) {
            $hex->townId = 'town';
        }

        $this->assertSame(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_allocation_prefers_two_one_annex_towns_over_one_two_annex_town(): void
    {
        $state = $this->state();
        $state->players[0]->palaceId = PalaceAbility::Palace08->value;
        $state->players[0]->availableAnnexes = 2;
        $state->availableTownTileIds = [TownTile::Coins->value, TownTile::Tools->value, TownTile::Books->value];
        $costly = $this->group('costly', [BuildingType::Guild, BuildingType::School]);
        $first = $this->group('first', [BuildingType::Palace, BuildingType::Tower, BuildingType::Workshop]);
        $second = $this->group('second', [BuildingType::Guild, BuildingType::School, BuildingType::Tower]);
        $first[1]->building->isNeutral = true;
        $second[2]->building->isNeutral = true;
        $state->board->hexes = [...$costly, ...$first, ...$second];

        $planning = app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [$costly, $first, $second], [64, 216, 216]);

        $this->assertSame(2, $planning->projectedTowns);
        $this->assertSame(672, $planning->score);
    }

    private function state(): GameStateData
    {
        return new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: null,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(),
            )],
            round: new RoundStateData(number: 6, phase: GamePhase::Actions),
            availableTownTileIds: [TownTile::Coins->value, TownTile::Tools->value],
        );
    }

    /**
     * @param list<BuildingType> $types
     * @return list<BoardHexStateData>
     */
    private function group(string $prefix, array $types): array
    {
        $group = [];
        foreach ($types as $index => $type) {
            $neighbors = [];
            if ($index > 0) {
                $neighbors[] = $prefix.'-'.($index - 1);
            }
            if ($index < count($types) - 1) {
                $neighbors[] = $prefix.'-'.($index + 1);
            }
            $group[] = new BoardHexStateData(
                id: $prefix.'-'.$index,
                q: $index,
                r: 0,
                terrain: TerrainType::Forest,
                initialTerrain: TerrainType::Forest,
                building: new BuildingStateData($type, 1),
                adjacentHexIds: $neighbors,
            );
        }

        return $group;
    }
}
