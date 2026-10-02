<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\BuildWorkshopOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\KnowledgeStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerActionOptionData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Data\SendScholarOptionData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\PowerAction;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TownTile;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Domain\Game\Services\BoardPositionProgressEvaluator;
use App\Domain\Game\Services\FinalScoringProgressEvaluator;
use App\Domain\Game\Services\GameActionRanker;
use App\Domain\Game\Services\GameStateEvaluator;
use App\Domain\Game\Services\RoundScoringProgressEvaluator;
use InvalidArgumentException;
use Tests\TestCase;

class GameActionRankerTest extends TestCase
{
    public function test_a_one_ply_search_prefers_an_upgrade_that_completes_a_town(): void
    {
        $state = $this->townUpgradeState();
        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame(210, $ranked[0]->scoreBreakdown->searchAdjustment);
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
        $this->assertSame(486, $ranked[0]->scoreBreakdown->searchAdjustment);
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
        $this->assertSame(276, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertSame($ranked[0]->score, $ranked[0]->scoreBreakdown->total());
        $this->assertSame(PendingInteractionType::ChooseCompetency, $ranked[0]->simulation->state->pendingInteraction->type);
        $this->assertSame([], $ranked[0]->simulation->state->players[0]->competencyIds);
        $this->assertSame([], $state->players[0]->competencyIds);
        $this->assertSame(BuildingType::Guild, $state->board->hexes[0]->building->type);
    }

    public function test_a_one_ply_search_values_the_income_and_books_awarded_by_a_palace(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->round->number = 1;
        $state->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 4);
        $state->availableCompetencyIds = [];
        $state->availablePalaceIds = [PalaceAbility::Palace01->value, PalaceAbility::Palace10->value];
        $state->board->hexes = [$this->buildingHex('guild', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::Guild;

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame(BuildingType::Palace, $ranked[0]->option->target);
        $this->assertSame(350, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertSame($ranked[0]->score, $ranked[0]->scoreBreakdown->total());
        $this->assertSame(PendingInteractionType::ChoosePalace, $ranked[0]->simulation->state->pendingInteraction->type);
        $this->assertNull($ranked[0]->simulation->state->players[0]->palaceId);
        $this->assertNull($state->players[0]->palaceId);
        $this->assertSame(BuildingType::Guild, $state->board->hexes[0]->building->type);
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
        $this->assertSame(276, $ranked[0]->scoreBreakdown->searchAdjustment);
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
        $this->assertSame(GameActionOptionType::Pass, $ranked[0]->option->type());
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

        $this->assertSame(GameActionOptionType::PaidTerraforming, $ranked[0]->option->type());
        $this->assertGreaterThan(0, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertSame($ranked[0]->score, $ranked[0]->scoreBreakdown->total());
        $this->assertNotNull($ranked[0]->simulation->state->pendingInteraction);
        $this->assertNull($state->board->hexes[1]->building);
        $this->assertSame(TerrainType::Mountain, $state->board->hexes[1]->terrain);
    }

    public function test_a_narrow_search_considers_construction_unlocked_by_a_conversion(): void
    {
        $state = $this->state();
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

    public function test_it_prefers_shipping_to_sending_the_last_scholar_when_it_opens_expansion(): void
    {
        $state = $this->state();
        $state->turnOrder = [1];
        $state->round->phase = GamePhase::Actions;
        $state->players[0]->resources->coins = 4;
        $state->players[0]->resources->tools = 0;
        $state->players[0]->resources->scholars = 1;
        $state->board->hexes = [
            $this->buildingHex('a', 1, ['water']),
            $this->emptyHex('water', ['a', 'b', 'c', 'd'], TerrainType::Water),
            $this->emptyHex('b', ['water']),
            $this->emptyHex('c', ['water']),
            $this->emptyHex('d', ['water']),
        ];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);

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
        $this->assertNotNull($coins);
        $this->assertGreaterThan($coins->score, $tools->score);
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

    public function test_board_position_priority_values_new_reachable_homeland(): void
    {
        $before = $this->state();
        $before->round->phase = GamePhase::Actions;
        $before->board->hexes = [
            $this->buildingHex('a', 1, ['water']),
            $this->emptyHex('water', ['a', 'target'], TerrainType::Water),
            $this->emptyHex('target', ['water']),
        ];
        $after = $before->deepCopy();
        $after->players[0]->shippingLevel = 1;

        $this->assertSame(
            3,
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
        $this->assertSame(34, $breakdown->development - $initialBreakdown->development);
        $this->assertSame($evaluator->execute($state, 1), $breakdown->total());
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

    public function test_state_evaluation_prefers_early_engine_income_over_small_victory_point_income(): void
    {
        $engineIncomeState = $this->state();
        $engineIncomeState->round->number = 1;
        $engineIncomeState->players[0]->competencyIds = [Competency::Competency01->value];

        $victoryPointIncomeState = $this->state();
        $victoryPointIncomeState->round->number = 1;
        $victoryPointIncomeState->players[0]->competencyIds = [Competency::Competency02->value];

        $evaluator = app(GameStateEvaluator::class);

        $this->assertGreaterThan(
            $evaluator->execute($victoryPointIncomeState, 1),
            $evaluator->execute($engineIncomeState, 1),
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

    public function test_it_returns_no_ranked_actions_when_none_are_legal(): void
    {
        $state = $this->state();
        $state->round->phase = GamePhase::Income;

        $this->assertSame([], app(GameActionRanker::class)->execute($state, 1));
    }

    public function test_it_penalizes_pass_when_a_non_pass_action_is_available(): void
    {
        $state = $this->state();
        $state->round->phase = GamePhase::Actions;
        $state->players[0]->resources->power = new PowerBowlsStateData(bowlTwo: 2);

        $rankedActions = app(GameActionRanker::class)->execute($state, 1, depth: 1);
        $passAction = collect($rankedActions)->first(
            static fn ($action): bool => $action->option->type() === GameActionOptionType::Pass,
        );

        $this->assertNotNull($passAction);
        $this->assertGreaterThan($passAction->score, $rankedActions[0]->score);
        $this->assertSame(GameActionOptionType::SacrificePower, $rankedActions[0]->option->type());
        $this->assertGreaterThan(0, $passAction->scoreBreakdown->passPenalty);
        $this->assertSame($passAction->score, $passAction->scoreBreakdown->total());
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

        $rankedActions = app(GameActionRanker::class)->execute(
            $state,
            2,
            depth: 3,
            branchLimit: 2,
            maxNodes: 1,
        );

        $this->assertCount(2, $rankedActions);
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
