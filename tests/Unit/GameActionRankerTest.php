<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionRanker;
use App\Domain\Game\Services\GameStateEvaluator;
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

    public function test_it_returns_no_ranked_actions_when_none_are_legal(): void
    {
        $state = $this->state();
        $state->round->phase = GamePhase::Income;

        $this->assertSame([], app(GameActionRanker::class)->execute($state, 1));
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
