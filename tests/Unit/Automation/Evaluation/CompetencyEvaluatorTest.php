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
