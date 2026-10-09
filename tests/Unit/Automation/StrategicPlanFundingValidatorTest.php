<?php

declare(strict_types=1);

namespace Tests\Unit\Automation;

use App\Domain\Automation\Data\StrategicPlanData;
use App\Domain\Automation\Enums\StrategicObjective;
use App\Domain\Automation\Services\StrategicPlanFundingValidator;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class StrategicPlanFundingValidatorTest extends TestCase
{
    public function test_gold_alone_cannot_fund_tools_but_a_legal_exchange_can(): void
    {
        $state = $this->state();
        $plan = new StrategicPlanData(StrategicObjective::FoundTown, [], [], 1, 2, 0, 100);
        $validator = app(StrategicPlanFundingValidator::class);
        $this->assertFalse($validator->execute($state, $state->players[0], $plan));
        $state->players[0]->resources->power->bowlThree = 3;
        $this->assertTrue($validator->execute($state, $state->players[0], $plan));
        $this->assertSame(3, $state->players[0]->resources->power->bowlThree);
        $this->assertSame(0, $state->players[0]->resources->tools);
    }

    public function test_one_power_supply_cannot_pay_for_both_tools_and_a_scholar(): void
    {
        $state = $this->state();
        $state->players[0]->resources->power = new PowerBowlsStateData(bowlThree: 5);
        $plan = new StrategicPlanData(StrategicObjective::ConnectNetwork, [], [], 1, 2, 0, 100, reservedScholars: 1);
        $this->assertFalse(app(StrategicPlanFundingValidator::class)->execute($state, $state->players[0], $plan));
        $state->players[0]->resources->power->bowlThree = 8;
        $this->assertTrue(app(StrategicPlanFundingValidator::class)->execute($state, $state->players[0], $plan));
    }

    public function test_upcoming_income_is_not_expected_after_round_six(): void
    {
        $state = $this->state();
        $state->players[0]->roundBonus = RoundBonus::RiverWorkshop;
        $plan = new StrategicPlanData(StrategicObjective::FoundTown, [], [], 1, 2, 0, 100);
        $validator = app(StrategicPlanFundingValidator::class);
        $state->round->number = 5;
        $this->assertTrue($validator->execute($state, $state->players[0], $plan));
        $state->round->number = 6;
        $this->assertFalse($validator->execute($state, $state->players[0], $plan));
        $state->round->phase = GamePhase::Income;
        $this->assertTrue($validator->execute($state, $state->players[0], $plan));
    }

    private function state(): GameStateData
    {
        $state = new GameStateData(
            players: [new GamePlayerStateData(
                1,
                null,
                PlayerColor::Green,
                Faction::Blessed,
                TerrainType::Forest,
                RoundBonus::Coins,
                resources: new PlayerResourcesData(coins: 31)
            )],
            round: new RoundStateData(6, GamePhase::Actions),
            turnOrder: [1],
        );
        $state->round->usedSharedActionIds = array_map(static fn (PowerAction $action): string => $action->value, PowerAction::cases());

        return $state;
    }
}
