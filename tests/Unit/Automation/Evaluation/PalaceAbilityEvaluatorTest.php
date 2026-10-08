<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\PalaceAbilityEvaluator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class PalaceAbilityEvaluatorTest extends TestCase
{
    public function test_special_action_has_value_only_while_it_is_unused_in_the_final_round(): void
    {
        [$state, $player] = $this->state(PalaceAbility::Palace01, round: 6);
        $evaluator = app(PalaceAbilityEvaluator::class);

        $this->assertSame(60, $evaluator->execute($state, $player));

        $player->usedSpecialActionIds = [PalaceAbility::Palace01->specialActionId()];

        $this->assertSame(0, $evaluator->execute($state, $player));
    }

    public function test_school_pass_scoring_uses_the_current_school_count(): void
    {
        [$state, $player] = $this->state(PalaceAbility::Palace07, [
            $this->building('school-1', BuildingType::School),
            $this->building('school-2', BuildingType::School),
            $this->building('workshop', BuildingType::Workshop),
        ]);

        $this->assertSame(360, app(PalaceAbilityEvaluator::class)->execute($state, $player));

        $state->round->passOrder = [1];
        $state->passedPlayerIds = [1];
        $this->assertSame(300, app(PalaceAbilityEvaluator::class)->execute($state, $player));
    }

    public function test_workshop_scoring_values_only_realistic_future_construction(): void
    {
        [$earlyState, $earlyPlayer] = $this->state(PalaceAbility::Palace12, [
            $this->building('workshop', BuildingType::Workshop),
        ], round: 2);
        [$lateState, $latePlayer] = $this->state(PalaceAbility::Palace12, [
            $this->building('workshop', BuildingType::Workshop),
        ], round: 6);

        $evaluator = app(PalaceAbilityEvaluator::class);

        $this->assertSame(160, $evaluator->execute($earlyState, $earlyPlayer));
        $this->assertSame(0, $evaluator->execute($lateState, $latePlayer));
    }

    public function test_guild_scoring_combines_unused_action_and_remaining_guild_potential(): void
    {
        [$state, $player] = $this->state(PalaceAbility::Palace13, [
            $this->building('workshop-1', BuildingType::Workshop),
            $this->building('workshop-2', BuildingType::Workshop),
            $this->building('guild', BuildingType::Guild),
        ]);

        $this->assertSame(253, app(PalaceAbilityEvaluator::class)->execute($state, $player));
    }

    public function test_used_special_action_retains_discounted_value_in_future_rounds(): void
    {
        [$state, $player] = $this->state(PalaceAbility::Palace01, round: 2);
        $this->assertSame(180, app(PalaceAbilityEvaluator::class)->execute($state, $player));
        $player->usedSpecialActionIds = [PalaceAbility::Palace01->specialActionId()];
        $this->assertSame(120, app(PalaceAbilityEvaluator::class)->execute($state, $player));
        $state->round->phase = GamePhase::Finished;
        $this->assertSame(0, app(PalaceAbilityEvaluator::class)->execute($state, $player));
    }

    public function test_free_upgrade_forecast_is_limited_by_existing_workshops(): void
    {
        [$state, $player] = $this->state(PalaceAbility::Palace04, [$this->building('workshop', BuildingType::Workshop)]);
        $this->assertSame(90, app(PalaceAbilityEvaluator::class)->execute($state, $player));
        $state->board->hexes = [];
        $this->assertSame(0, app(PalaceAbilityEvaluator::class)->execute($state, $player));
    }

    public function test_knowledge_action_uses_the_calibrated_value_of_optional_future_steps(): void
    {
        [$state, $player] = $this->state(PalaceAbility::Palace06, round: 2);
        $evaluator = app(PalaceAbilityEvaluator::class);

        $this->assertSame(60, $evaluator->execute($state, $player));
        $player->usedSpecialActionIds = [PalaceAbility::Palace06->specialActionId()];
        $this->assertSame(40, $evaluator->execute($state, $player));
        $state->round->phase = GamePhase::Finished;
        $this->assertSame(0, $evaluator->execute($state, $player));
    }

    public function test_previous_round_pass_order_does_not_hide_available_palace_actions(): void
    {
        [$state, $player] = $this->state(PalaceAbility::Palace01, round: 2);
        $state->round->passOrder = [1];

        $this->assertSame(180, app(PalaceAbilityEvaluator::class)->execute($state, $player));
        $state->passedPlayerIds = [1];
        $this->assertSame(120, app(PalaceAbilityEvaluator::class)->execute($state, $player));
    }

    /**
     * @param list<BoardHexStateData> $hexes
     * @return array{GameStateData, GamePlayerStateData}
     */
    private function state(PalaceAbility $palace, array $hexes = [], int $round = 1): array
    {
        $player = new GamePlayerStateData(
            playerId: 1,
            userId: 10,
            color: PlayerColor::Black,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(),
            palaceId: $palace->value,
        );
        $state = new GameStateData(
            board: new BoardStateData(hexes: $hexes),
            players: [$player],
            round: new RoundStateData(number: $round, phase: GamePhase::Actions),
        );

        return [$state, $player];
    }

    private function building(string $id, BuildingType $buildingType): BoardHexStateData
    {
        return new BoardHexStateData(
            id: $id,
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            building: new BuildingStateData($buildingType, 1),
        );
    }
}
