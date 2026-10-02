<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Enums\GameActionSelectionReason;
use App\Domain\Automation\Services\GameActionSelector;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Data\RewardDistributionOptionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\ChooseCompetencyOptionData;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Setup\Data\PlanningBundleData;
use App\Domain\GameEngine\Setup\Data\PlayerPlanningSelectionData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class GameActionSelectorTest extends TestCase
{
    public function test_it_reports_compact_search_diagnostics(): void
    {
        $state = $this->state();
        $state->players[0]->knowledge->unassignedSteps = 1;
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChooseStartingResources,
            1,
            context: ['bookCount' => 0, 'knowledgeStepCount' => 1],
        );

        $selector = app(GameActionSelector::class);
        $diagnostics = $selector->selectWithDiagnostics(
            $state,
            1,
            GameBotDifficulty::Fast,
        );

        $this->assertNotNull($diagnostics->selected);
        $this->assertCount(4, $diagnostics->candidates);
        $this->assertSame(
            $diagnostics->selected->option->type()->value,
            $diagnostics->candidates[0]->type->value,
        );
        $this->assertSame($diagnostics->selected->score, $diagnostics->candidates[0]->score);
        $this->assertSame($diagnostics->selected->option, $diagnostics->candidates[0]->option);
        $this->assertSame(GameActionSelectionReason::HighestScore, $diagnostics->selectionReason);
        $this->assertSame(1, $diagnostics->candidates[0]->rank);
        $this->assertSame(0, $diagnostics->candidates[0]->scoreDelta);
        $this->assertTrue($diagnostics->candidates[0]->selected);
        $this->assertFalse($diagnostics->candidates[1]->selected);
        foreach ($diagnostics->candidates as $candidate) {
            $this->assertSame($candidate->score, $candidate->scoreBreakdown->total());
        }
        $this->assertSame($diagnostics->selected->scoreBreakdown, $diagnostics->candidates[0]->scoreBreakdown);
        $this->assertGreaterThanOrEqual(0, $diagnostics->visitedNodes);
        $this->assertGreaterThanOrEqual(0, $diagnostics->durationMilliseconds);
        $this->assertGreaterThan(0, $diagnostics->searchTimings->optionFindingCalls);
        $this->assertGreaterThan(0, $diagnostics->searchTimings->simulationCalls);
        $this->assertGreaterThan(0, $diagnostics->searchTimings->stateEvaluationCalls);
        $this->assertGreaterThan(0, $diagnostics->searchTimings->simulationNanoseconds);
        $this->assertGreaterThan(0, $diagnostics->searchTimings->simulationStateCopyNanoseconds);
        $this->assertGreaterThanOrEqual(0, $diagnostics->searchTimings->simulationExecutionNanoseconds);
        $this->assertSame(
            $diagnostics->searchTimings->simulationNanoseconds,
            $diagnostics->searchTimings->simulationStateCopyNanoseconds + $diagnostics->searchTimings->simulationExecutionNanoseconds,
        );
        $state->pendingInteraction = null;
        $actionTimings = $diagnostics->searchTimings->simulationsByAction;
        $this->assertNotEmpty($actionTimings);
        $this->assertSame($diagnostics->searchTimings->simulationCalls, array_sum(array_column($actionTimings, 'calls')));
        $this->assertSame($diagnostics->searchTimings->simulationNanoseconds, array_sum(array_column($actionTimings, 'nanoseconds')));
        foreach ($actionTimings as $type => $timings) {
            $this->assertSame($type, $timings->type->value);
            $this->assertSame($timings->nanoseconds, $timings->stateCopyNanoseconds + $timings->executionNanoseconds);
            $this->assertGreaterThan(0, $timings->maximumNanoseconds);
            $this->assertLessThanOrEqual($timings->nanoseconds, $timings->maximumNanoseconds);
        }
        $state->round->phase = GamePhase::Income;
        $emptyDiagnostics = $selector->selectWithDiagnostics($state, 1);
        $this->assertSame(0, $emptyDiagnostics->searchTimings->simulationCalls);
        $this->assertSame([], $emptyDiagnostics->searchTimings->simulationsByAction);
        $this->assertGreaterThan(0, $diagnostics->searchTimings->simulationCalls);
        $this->assertNotSame($diagnostics->searchTimings, $emptyDiagnostics->searchTimings);
    }

    public function test_it_selects_the_highest_ranked_legal_action(): void
    {
        $state = $this->state();
        $state->players[0]->knowledge->banking = 2;
        $state->players[0]->knowledge->unassignedSteps = 1;
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChooseStartingResources,
            1,
            context: ['bookCount' => 0, 'knowledgeStepCount' => 1],
        );

        $selection = app(GameActionSelector::class)->execute($state, 1, GameBotDifficulty::Fast);

        $this->assertNotNull($selection);
        $this->assertInstanceOf(RewardDistributionOptionData::class, $selection->option);
        $this->assertSame(1, array_sum($selection->option->knowledgeCounts));
        $this->assertSame(
            3,
            array_sum([
                $selection->simulation->state->players[0]->knowledge->banking,
                $selection->simulation->state->players[0]->knowledge->law,
                $selection->simulation->state->players[0]->knowledge->engineering,
                $selection->simulation->state->players[0]->knowledge->medicine,
            ]),
        );
        $this->assertSame(2, $state->players[0]->knowledge->banking);
    }

    public function test_it_returns_null_when_the_player_has_no_legal_actions(): void
    {
        $state = $this->state();
        $state->round->phase = GamePhase::Income;

        $this->assertNull(app(GameActionSelector::class)->execute($state, 1));

        $diagnostics = app(GameActionSelector::class)->selectWithDiagnostics($state, 1);

        $this->assertSame(GameActionSelectionReason::NoLegalActions, $diagnostics->selectionReason);
        $this->assertSame([], $diagnostics->candidates);
    }

    public function test_it_selects_a_starting_competency_for_monks_without_an_interaction_reason(): void
    {
        $state = $this->state();
        $state->players[0]->faction = Faction::Monks;
        $state->availableCompetencyIds = [Competency::Competency04->value];
        $state->turnOrder = [1];
        $state->planningSelections = [new PlayerPlanningSelectionData(
            1,
            new PlanningBundleData(TerrainType::Forest, Faction::Monks, RoundBonus::Coins),
        )];
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChooseCompetency,
            1,
            [Competency::Competency04->value],
        );

        $selection = app(GameActionSelector::class)->execute($state, 1, GameBotDifficulty::Fast);

        $this->assertNotNull($selection);
        $this->assertInstanceOf(ChooseCompetencyOptionData::class, $selection->option);
        $this->assertSame(Competency::Competency04, $selection->option->competency);
    }

    public function test_difficulty_profiles_increase_search_limits(): void
    {
        $fast = GameBotDifficulty::Fast->searchParameters();
        $balanced = GameBotDifficulty::Balanced->searchParameters();
        $strong = GameBotDifficulty::Strong->searchParameters();

        $this->assertLessThan($balanced['depth'], $fast['depth']);
        $this->assertLessThan($strong['depth'], $balanced['depth']);
        $this->assertLessThan($balanced['maxNodes'], $fast['maxNodes']);
        $this->assertLessThan($strong['maxNodes'], $balanced['maxNodes']);
        $this->assertLessThan($balanced['maxTimeMilliseconds'], $fast['maxTimeMilliseconds']);
        $this->assertLessThan($strong['maxTimeMilliseconds'], $balanced['maxTimeMilliseconds']);
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
            round: new RoundStateData(phase: GamePhase::Setup),
        );
    }
}
