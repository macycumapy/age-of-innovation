<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Research;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Research\Services\SendScholarOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class SendScholarSimulatorTest extends TestCase
{
    public function test_it_generates_and_simulates_placed_scholar_without_mutating_the_source(): void
    {
        $state = $this->state();
        $options = app(SendScholarOptionFinder::class)->execute($state, $state->players[0]);
        $option = collect($options)->first(
            static fn ($candidate): bool => $candidate->discipline === KnowledgeDiscipline::Law && $candidate->place,
        );

        $this->assertCount(8, $options);
        $this->assertNotNull($option);
        $this->assertSame(2, $option->steps);
        $this->assertSame(1, $option->slotIndex);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);

        $this->assertSame(0, $state->players[0]->knowledge->law);
        $this->assertSame(7, $state->players[0]->scholarPoolSize);
        $this->assertSame([], $state->players[0]->scholarDisciplineIds);
        $this->assertSame(2, $state->players[0]->resources->scholars);
        $this->assertSame(2, $simulation->state->players[0]->knowledge->law);
        $this->assertSame(6, $simulation->state->players[0]->scholarPoolSize);
        $this->assertSame(['law'], $simulation->state->players[0]->scholarDisciplineIds);
        $this->assertSame([1], $simulation->state->players[0]->scholarSlotIndexes);
        $this->assertSame(1, $simulation->state->players[0]->resources->scholars);
        $this->assertSame(24, $simulation->state->players[0]->victoryPoints);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    private function state(): GameStateData
    {
        return new GameStateData(
            players: [
                new GamePlayerStateData(
                    playerId: 1,
                    userId: 10,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::SendScholar,
                    competencyIds: [Competency::Competency09->value],
                    resources: new PlayerResourcesData(scholars: 2),
                ),
                new GamePlayerStateData(
                    playerId: 2,
                    userId: 20,
                    color: PlayerColor::Red,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::Coins,
                    scholarDisciplineIds: [KnowledgeDiscipline::Law->value],
                    scholarSlotIndexes: [0],
                ),
            ],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
    }
}
