<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Data\SendScholarOptionData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionRanker;
use App\Domain\Game\Services\GameStateEvaluator;
use InvalidArgumentException;
use Tests\TestCase;

class GameActionRankerTest extends TestCase
{
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
        $this->assertSame(1, $rankedActions[0]->option->knowledgeCounts['banking']);
        $this->assertSame(3, $rankedActions[0]->simulation->state->players[0]->knowledge->banking);
        $this->assertSame(2, $state->players[0]->knowledge->banking);
        $this->assertGreaterThan($rankedActions[1]->score, $rankedActions[0]->score);
    }

    public function test_state_evaluation_rewards_resources_and_progress(): void
    {
        $state = $this->state();
        $evaluator = app(GameStateEvaluator::class);
        $initialScore = $evaluator->execute($state, 1);

        $state->players[0]->resources->tools++;
        $state->players[0]->knowledge->law++;

        $this->assertGreaterThan($initialScore, $evaluator->execute($state, 1));
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

        $copy->players[0]->resources->coins++;

        $this->assertSame(3, $state->players[0]->resources->coins);
        $this->assertSame(4, $copy->players[0]->resources->coins);
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
}
