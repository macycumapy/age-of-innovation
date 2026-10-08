<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Evaluation\Services\BoardPositionProgressEvaluator;
use App\Domain\Automation\Evaluation\Services\FinalScoringProgressEvaluator;
use App\Domain\Automation\Evaluation\Services\GameStateEvaluator;
use App\Domain\Automation\Evaluation\Services\PassValueEvaluator;
use App\Domain\Automation\Evaluation\Services\RoundScoringProgressEvaluator;
use App\Domain\Automation\Services\GameActionRanker;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Data\BuildWorkshopOptionData;
use App\Domain\GameEngine\Board\Data\PaidTerraformingOptionData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerActionOptionData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
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
        $state->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 4);
        $state->availableCompetencyIds = [];
        $state->availablePalaceIds = [PalaceAbility::Palace01->value, PalaceAbility::Palace10->value];
        $state->board->hexes = [$this->buildingHex('guild', 1, [])];
        $state->board->hexes[0]->building->type = BuildingType::Guild;

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1, auxiliaryActionsRemaining: 0);

        $this->assertSame(GameActionOptionType::UpgradeBuilding, $ranked[0]->option->type());
        $this->assertSame(BuildingType::Palace, $ranked[0]->option->target);
        $this->assertSame(488, $ranked[0]->scoreBreakdown->searchAdjustment);
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
        $this->assertGreaterThan(300, $palace->scoreBreakdown->searchAdjustment);
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

        $this->assertSame(GameActionOptionType::PaidTerraforming, $ranked[0]->option->type());
        $this->assertGreaterThan(0, $ranked[0]->scoreBreakdown->searchAdjustment);
        $this->assertSame($ranked[0]->score, $ranked[0]->scoreBreakdown->total());
        $this->assertNotNull($ranked[0]->simulation->state->pendingInteraction);
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
            $this->buildingHex('c', 1, []),
            $this->buildingHex('d', 1, []),
            $this->emptyHex('target', ['a'], TerrainType::Mountain),
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

    public function test_board_position_strongly_values_an_upgrade_towards_a_town(): void
    {
        $before = $this->state();
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
        $this->assertSame(90, $pass->scoreBreakdown->searchAdjustment);
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
        $workshopState->players[0]->resources = new PlayerResourcesData(coins: 6, tools: 3);
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

        $this->assertSame(-52, $evaluator->execute($after, 1) - $evaluator->execute($before, 1));
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
            tools: 0,
            power: new PowerBowlsStateData(bowlOne: 2, bowlTwo: 2, bowlThree: 3),
        );
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
