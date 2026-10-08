<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\InnovationEvaluator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class InnovationEvaluatorTest extends TestCase
{
    public function test_trade_routes_forecasts_only_remaining_pass_rewards(): void
    {
        [$state, $player] = $this->state(Innovation::TradeRoutes);
        $state->board->hexes = [new BoardHexStateData(
            id: 'guild',
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            building: new BuildingStateData(BuildingType::Guild, 1),
        )];
        $evaluator = app(InnovationEvaluator::class);

        $this->assertSame(100, $evaluator->execute($state, $player));
        $state->round->passOrder = [1];
        $state->passedPlayerIds = [1];
        $this->assertSame(80, $evaluator->execute($state, $player));
        $state->round->phase = GamePhase::Finished;
        $this->assertSame(0, $evaluator->execute($state, $player));
    }

    public function test_professor_forecasts_unused_actions_with_receiving_round_scholar_values(): void
    {
        [$state, $player] = $this->state(Innovation::Professor);
        $evaluator = app(InnovationEvaluator::class);

        $this->assertSame(293, $evaluator->execute($state, $player));
        $player->usedSpecialActionIds = [Innovation::Professor->specialActionId()];
        $this->assertSame(223, $evaluator->execute($state, $player));
        $state->round->number = 6;
        $this->assertSame(0, $evaluator->execute($state, $player));
        $player->usedSpecialActionIds = [];
        $this->assertSame(32, $evaluator->execute($state, $player));
        $state->round->passOrder = [1];
        $state->passedPlayerIds = [1];
        $this->assertSame(0, $evaluator->execute($state, $player));
    }

    public function test_professor_keeps_its_points_but_cannot_forecast_scholars_with_an_empty_pool(): void
    {
        [$state, $player] = $this->state(Innovation::Professor);
        $player->scholarPoolSize = 0;

        $this->assertSame(150, app(InnovationEvaluator::class)->execute($state, $player));
        $this->assertSame(0, $player->scholarPoolSize);
    }

    public function test_immediate_rewards_and_recurring_income_are_not_counted_again(): void
    {
        $evaluator = app(InnovationEvaluator::class);

        foreach (Innovation::cases() as $innovation) {
            if (in_array($innovation, [Innovation::Professor, Innovation::TradeRoutes, Innovation::DeusExMachina], true)) {
                continue;
            }

            [$state, $player] = $this->state($innovation);
            $this->assertSame(0, $evaluator->execute($state, $player), $innovation->value);
        }
    }

    public function test_free_spade_without_a_reachable_target_has_no_forecast_value(): void
    {
        [$state, $player] = $this->state(Innovation::DeusExMachina);
        $this->assertSame(0, app(InnovationEvaluator::class)->execute($state, $player));
    }

    public function test_purchase_potential_distinguishes_immediate_points_from_repeatable_rewards(): void
    {
        [$state, $player] = $this->state(Innovation::Professor);
        $evaluator = app(InnovationEvaluator::class);
        $this->assertSame(293, $evaluator->purchasePotential($state, $player, Innovation::Professor));
        $this->assertSame(100, $evaluator->purchasePotential($state, $player, Innovation::Architecture));
        $this->assertSame(0, $evaluator->purchasePotential($state, $player, Innovation::Steel));
        $this->assertSame(175, $evaluator->purchasePotential($state, $player, Innovation::Guild));
        $this->assertSame([Innovation::Professor->value], $player->inventionIds);
    }

    public function test_previous_round_pass_order_does_not_hide_an_unused_professor_action(): void
    {
        [$state, $player] = $this->state(Innovation::Professor);
        $state->round->passOrder = [1];
        $this->assertSame(293, app(InnovationEvaluator::class)->execute($state, $player));
    }

    /** @return array{GameStateData, GamePlayerStateData} */
    private function state(Innovation $innovation): array
    {
        $player = new GamePlayerStateData(
            playerId: 1,
            userId: 10,
            color: PlayerColor::Black,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            inventionIds: [$innovation->value],
        );

        return [new GameStateData(
            board: new BoardStateData(hexes: []),
            players: [$player],
            round: new RoundStateData(number: 2, phase: GamePhase::Actions),
        ), $player];
    }
}
