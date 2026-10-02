<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Research;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\ChooseCompetencyOptionData;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class ChooseCompetencySimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_an_action_phase_competency_choice(): void
    {
        $state = new GameStateData(
            schemaVersion: 4,
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableCompetencyIds: [Competency::Competency04->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseCompetency,
                1,
                [Competency::Competency04->value],
                ['reason' => 'innovation'],
            ),
        );
        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof ChooseCompetencyOptionData,
        ));

        $this->assertCount(1, $options);
        $this->assertSame(GameActionOptionType::ChooseCompetency, $options[0]->type());
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame([], $state->players[0]->competencyIds);
        $this->assertSame([Competency::Competency04->value], $simulation->state->players[0]->competencyIds);
        $this->assertSame(1, $simulation->state->players[0]->resources->tools);
        $this->assertSame(2, $simulation->state->players[0]->resources->coins);
        $this->assertSame(25, $simulation->state->players[0]->victoryPoints);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }
}
