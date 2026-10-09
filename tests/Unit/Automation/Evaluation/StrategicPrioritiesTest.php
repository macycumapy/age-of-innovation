<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\StrategicPriorities;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use PHPUnit\Framework\TestCase;

class StrategicPrioritiesTest extends TestCase
{
    public function test_settlement_priority_rises_from_economy_to_realization(): void
    {
        foreach ([1 => 40, 2 => 40, 3 => 70, 4 => 100, 5 => 100, 6 => 100] as $round => $expected) {
            $state = new GameStateData(round: new RoundStateData(number: $round, phase: GamePhase::Actions));
            $this->assertSame($expected, StrategicPriorities::settlementValue($state, 100));
            $this->assertSame(-$expected, StrategicPriorities::settlementValue($state, -100));
        }
    }

    public function test_town_round_goal_and_starting_placement_keep_full_priority(): void
    {
        $state = new GameStateData(round: new RoundStateData(number: 1, phase: GamePhase::Actions));
        $state->round->scoringTileId = RoundScoringTile::TownEngineering->value;
        $this->assertSame(100, StrategicPriorities::settlementValue($state, 100));
        $state->round->scoringTileId = null;
        $state->round->phase = GamePhase::Setup;
        $this->assertSame(100, StrategicPriorities::settlementValue($state, 100));
    }
}
