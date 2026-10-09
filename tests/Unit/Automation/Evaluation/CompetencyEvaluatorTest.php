<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\CompetencyEvaluator;
use App\Domain\Automation\Evaluation\Services\GameStateEvaluator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class CompetencyEvaluatorTest extends TestCase
{
    public function test_annex_competency_is_preferred_only_when_it_enables_a_new_town(): void
    {
        [$state, $player] = $this->state(Competency::Competency06);
        $player->competencyIds = [];
        $state->round->number = 6;
        foreach (\App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline::cases() as $discipline) {
            $player->knowledge->{$discipline->value} = 10;
        }
        $state->turnOrder = [1];
        $state->availableTownTileIds = [\App\Domain\GameEngine\Towns\Enums\TownTile::Coins->value];
        $state->availableCompetencyIds = [Competency::Competency06->value, Competency::Competency04->value];
        $state->board->hexes = [
            new BoardHexStateData('a', 0, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['b'], building: new BuildingStateData(BuildingType::Guild, 1)),
            new BoardHexStateData('b', 1, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['a', 'c'], building: new BuildingStateData(BuildingType::Guild, 1)),
            new BoardHexStateData('c', 2, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['b'], building: new BuildingStateData(BuildingType::Guild, 1)),
        ];
        $state->pendingInteraction = new \App\Domain\GameEngine\Interactions\Data\PendingInteractionData(
            \App\Domain\GameEngine\Interactions\Enums\PendingInteractionType::ChooseCompetency,
            1,
            $state->availableCompetencyIds,
            ['reason' => 'innovation'],
        );
        $ranker = app(\App\Domain\Automation\Services\GameActionRanker::class);
        $this->assertSame(Competency::Competency06, $ranker->execute($state, 1, depth: 1)[0]->option->competency);
        $state->board->hexes[2]->adjacentHexIds[] = 'd';
        $state->board->hexes[] = new BoardHexStateData('d', 3, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['c'], building: new BuildingStateData(BuildingType::Workshop, 1));
        foreach ($state->board->hexes as $hex) {
            $hex->townId = 'existing';
        }
        $ranked = $ranker->execute($state, 1, depth: 1);
        $this->assertSame(Competency::Competency04, $ranked[0]->option->competency);
        $this->assertSame([], $player->competencyIds);
        $this->assertSame(0, $player->availableAnnexes);
    }

    public function test_tower_income_adjustment_preserves_capacity_limits_and_does_not_mutate_income(): void
    {
        [$state, $player] = $this->state(Competency::Competency10);
        $income = new \App\Domain\GameEngine\Economy\Data\IncomeReceiptData(playerId: 1, tools: 0, coins: 4, scholars: 0, power: 5, books: 0, knowledgeSteps: 0, victoryPoints: 0);
        $projected = clone $income;
        $projected->power = 1;
        $withoutTower = clone $projected;
        $withoutTower->coins = 2;
        $values = app(\App\Domain\Automation\Evaluation\Services\IncomeValueEvaluator::class);
        $towerValue = $values->execute($projected, 5) - $values->execute($withoutTower, 5);
        $evaluator = app(CompetencyEvaluator::class);

        $this->assertSame(intdiv($towerValue, 5) - $towerValue, $evaluator->incomeScoreAdjustment($state, $player, $income, $projected, 5));
        $this->assertSame(4, $income->coins);
        $this->assertSame(5, $income->power);
        $this->assertSame(1, $projected->power);
        $player->competencyIds = [Competency::Competency02->value];
        $this->assertSame(0, $evaluator->incomeScoreAdjustment($state, $player, $income, $projected, 5));
    }

    public function test_tower_income_is_discounted_without_an_affordable_placement_but_not_after_building(): void
    {
        [$state, $player] = $this->state(Competency::Competency10);
        $state->board->hexes = [
            new BoardHexStateData('home', 0, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['target'], building: new BuildingStateData(BuildingType::Guild, 1)),
            new BoardHexStateData('target', 1, 0, TerrainType::Mountain, TerrainType::Mountain, adjacentHexIds: ['home']),
        ];
        $state->pendingInteraction = new \App\Domain\GameEngine\Interactions\Data\PendingInteractionData(
            \App\Domain\GameEngine\Interactions\Enums\PendingInteractionType::PlaceNeutralBuilding,
            1,
            [],
            ['buildingType' => BuildingType::Tower->value],
        );
        $evaluator = app(GameStateEvaluator::class);
        $unaffordable = $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;
        $actualIncome = \App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator::calculate($player, $state->board, includeRoundBonus: false);
        $this->assertSame(4, $actualIncome->coins);
        $this->assertSame(3, $actualIncome->power);
        $player->resources->tools = 3;
        $funded = $evaluator->evaluateWithBreakdown($state, 1)->futureIncome;
        $this->assertGreaterThan($unaffordable, $funded);
        $state->pendingInteraction = null;
        $this->assertSame($unaffordable, $evaluator->evaluateWithBreakdown($state, 1)->futureIncome);
        $state->board->hexes[1]->building = new BuildingStateData(BuildingType::Tower, 1, isNeutral: true);
        $this->assertSame($funded, $evaluator->evaluateWithBreakdown($state, 1)->futureIncome);
        $this->assertSame(3, $player->resources->tools);
    }

    public function test_competency_choice_prefers_a_reward_to_a_tower_that_cannot_be_placed(): void
    {
        [$state, $player] = $this->state(Competency::Competency10);
        $player->competencyIds = [];
        $state->turnOrder = [1];
        $state->availableCompetencyIds = [Competency::Competency04->value, Competency::Competency10->value];
        $state->pendingInteraction = new \App\Domain\GameEngine\Interactions\Data\PendingInteractionData(
            \App\Domain\GameEngine\Interactions\Enums\PendingInteractionType::ChooseCompetency,
            1,
            $state->availableCompetencyIds,
            ['reason' => 'innovation'],
        );
        $ranked = app(\App\Domain\Automation\Services\GameActionRanker::class)->execute($state, 1, depth: 1);

        $this->assertSame(Competency::Competency04, $ranked[0]->option->competency);
        $this->assertSame([], $player->competencyIds);
        $state->board->hexes = [
            new BoardHexStateData('a', 0, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['b'], building: new BuildingStateData(BuildingType::Guild, 1)),
            new BoardHexStateData('b', 1, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['a', 'c'], building: new BuildingStateData(BuildingType::Guild, 1)),
            new BoardHexStateData('c', 2, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['b', 'target'], building: new BuildingStateData(BuildingType::Guild, 1)),
            new BoardHexStateData('target', 3, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['c']),
        ];
        $state->availableTownTileIds = [\App\Domain\GameEngine\Towns\Enums\TownTile::Coins->value];
        $ranked = app(\App\Domain\Automation\Services\GameActionRanker::class)->execute($state, 1, depth: 1);
        $towerChoice = collect($ranked)->first(static fn ($action): bool => $action->option->competency === Competency::Competency10);
        $this->assertNotNull($towerChoice);
        $placements = app(\App\Domain\Automation\Services\GameActionRanker::class)->execute($towerChoice->simulation->state, 1, depth: 1);
        $this->assertSame('target', $placements[0]->option->hexId);
    }

    public function test_pass_rewards_depend_on_towns_knowledge_and_remaining_passes(): void
    {
        [$state, $player] = $this->state(Competency::Competency08);
        $player->townTileIds = ['town-1', 'town-2'];
        $this->assertSame(120, app(CompetencyEvaluator::class)->execute($state, $player));
        $state->round->passOrder = [1];
        $state->passedPlayerIds = [1];
        $this->assertSame(80, app(CompetencyEvaluator::class)->execute($state, $player));
        $player->competencyIds = [Competency::Competency12->value];
        $player->knowledge->banking = 5;
        $player->knowledge->law = 4;
        $player->knowledge->engineering = 3;
        $player->knowledge->medicine = 2;
        $this->assertSame(40, app(CompetencyEvaluator::class)->execute($state, $player));
        $state->round->phase = GamePhase::Finished;
        $this->assertSame(0, app(CompetencyEvaluator::class)->execute($state, $player));
    }

    public function test_power_action_values_actual_bowls_without_mutation_and_resets_next_round(): void
    {
        [$state, $player] = $this->state(Competency::Competency07);
        $player->resources->power->bowlOne = 2;
        $player->resources->power->bowlTwo = 2;
        $player->resources->power->bowlThree = 0;
        $this->assertSame(42, app(CompetencyEvaluator::class)->execute($state, $player));
        $this->assertSame(2, $player->resources->power->bowlOne);
        $player->usedSpecialActionIds = [Competency::Competency07->value];
        $this->assertSame(20, app(CompetencyEvaluator::class)->execute($state, $player));
        $state->round->number = 6;
        $this->assertSame(0, app(CompetencyEvaluator::class)->execute($state, $player));
    }

    public function test_scholar_reward_uses_current_supply(): void
    {
        [$state, $player] = $this->state(Competency::Competency09);
        $state->round->number = 6;
        $player->resources->scholars = 3;
        $this->assertSame(30, app(CompetencyEvaluator::class)->execute($state, $player));
        $player->resources->scholars = 0;
        $this->assertSame(0, app(CompetencyEvaluator::class)->execute($state, $player));
    }

    public function test_scholar_rewards_do_not_forecast_income_from_an_empty_pool(): void
    {
        [$state, $player] = $this->state(Competency::Competency09);
        $state->round->number = 3;
        $state->board->hexes = [new BoardHexStateData(
            id: 'school',
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            adjacentHexIds: [],
            building: new BuildingStateData(type: BuildingType::School, ownerPlayerId: 1),
        )];
        $player->resources->scholars = 0;
        $player->scholarPoolSize = 0;

        $this->assertSame(0, app(CompetencyEvaluator::class)->execute($state, $player));
    }

    public function test_scholar_forecast_uses_permanent_income_not_the_current_round_bonus(): void
    {
        [$state, $player] = $this->state(Competency::Competency09);
        $player->resources->scholars = 0;
        $player->roundBonus = RoundBonus::SendScholar;
        $state->board->hexes = [new BoardHexStateData(
            'school',
            0,
            0,
            TerrainType::Forest,
            TerrainType::Forest,
            building: new BuildingStateData(BuildingType::School, 1)
        )];

        $this->assertSame(20, app(CompetencyEvaluator::class)->execute($state, $player));
    }

    public function test_edge_reward_requires_reachable_empty_land(): void
    {
        [$state, $player] = $this->state(Competency::Competency11);
        $state->board->hexes = [
            new BoardHexStateData('home', 0, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['edge'], building: new BuildingStateData(BuildingType::Workshop, 1)),
            new BoardHexStateData('edge', 1, 0, TerrainType::Forest, TerrainType::Forest),
            new BoardHexStateData('remote', 4, 0, TerrainType::Forest, TerrainType::Forest),
        ];
        $state->board->edgeHexIds = ['edge', 'remote'];
        $this->assertSame(15, app(CompetencyEvaluator::class)->execute($state, $player));
        $state->board->hexes[1]->building = new BuildingStateData(BuildingType::Workshop, 2);
        $this->assertSame(0, app(CompetencyEvaluator::class)->execute($state, $player));
    }

    public function test_income_and_immediate_rewards_are_not_counted_twice(): void
    {
        foreach ([Competency::Competency01, Competency::Competency02, Competency::Competency03, Competency::Competency04, Competency::Competency05, Competency::Competency06, Competency::Competency10] as $competency) {
            [$state, $player] = $this->state($competency);
            $this->assertSame(0, app(CompetencyEvaluator::class)->execute($state, $player));
        }
    }

    public function test_state_evaluation_includes_the_future_pass_reward(): void
    {
        [$state, $player] = $this->state(Competency::Competency08);
        $before = app(GameStateEvaluator::class)->execute($state, 1);
        $player->townTileIds = ['town'];
        $this->assertSame(140, app(GameStateEvaluator::class)->execute($state, 1) - $before);
    }

    /** @return array{GameStateData, GamePlayerStateData} */
    private function state(Competency $competency): array
    {
        $player = new GamePlayerStateData(1, null, PlayerColor::Black, Faction::Blessed, TerrainType::Forest, RoundBonus::Coins, competencyIds: [$competency->value]);
        return [new GameStateData(board: new BoardStateData(), players: [$player], round: new RoundStateData(number: 4, phase: GamePhase::Actions)), $player];
    }
}
