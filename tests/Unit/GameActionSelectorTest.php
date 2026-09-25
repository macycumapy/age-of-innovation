<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\ChooseCompetencyOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionSelector;
use Tests\TestCase;

class GameActionSelectorTest extends TestCase
{
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
        $this->assertSame(1, $selection->option->knowledgeCounts['banking']);
        $this->assertSame(2, $state->players[0]->knowledge->banking);
    }

    public function test_it_returns_null_when_the_player_has_no_legal_actions(): void
    {
        $state = $this->state();
        $state->round->phase = GamePhase::Income;

        $this->assertNull(app(GameActionSelector::class)->execute($state, 1));
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
