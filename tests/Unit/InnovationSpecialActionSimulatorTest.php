<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\InnovationSpecialActionOptionFinder;
use Tests\TestCase;

class InnovationSpecialActionSimulatorTest extends TestCase
{
    public function test_it_simulates_professor_without_mutating_the_source(): void
    {
        $state = new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                inventionIds: [Innovation::Professor->value],
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
        $options = app(InnovationSpecialActionOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertCount(1, $options);
        $this->assertSame(Innovation::Professor, $options[0]->innovation);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(0, $state->players[0]->resources->scholars);
        $this->assertSame(20, $state->players[0]->victoryPoints);
        $this->assertSame([], $state->players[0]->usedSpecialActionIds);
        $this->assertSame(1, $simulation->state->players[0]->resources->scholars);
        $this->assertSame(23, $simulation->state->players[0]->victoryPoints);
        $this->assertSame([Innovation::Professor->specialActionId()], $simulation->state->players[0]->usedSpecialActionIds);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
        $this->assertSame(10, $simulation->nextActiveUserId);
    }
}
