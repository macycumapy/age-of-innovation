<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\ChooseCompetencyOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionOptionFinder;
use App\Domain\Game\Services\GameActionSimulator;
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
