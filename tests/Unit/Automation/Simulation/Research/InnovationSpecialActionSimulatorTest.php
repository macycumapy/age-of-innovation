<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Research;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Services\InnovationSpecialActionOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
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
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }
}
