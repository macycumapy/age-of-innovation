<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\PlayerAbilities;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\PlayerAbilities\Services\PlayerSpecialActionOptionFinder;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class PlayerSpecialActionSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_philosopher_book_choices(): void
    {
        $state = new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Philosophers,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
        $options = app(PlayerSpecialActionOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertCount(4, $options);
        $lawOption = collect($options)->firstWhere('discipline', KnowledgeDiscipline::Law);
        $this->assertNotNull($lawOption);
        $this->assertSame(GameActionOptionType::UseFactionAction, $lawOption->type());

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $lawOption);

        $this->assertSame(0, $state->players[0]->resources->books->law);
        $this->assertSame(1, $simulation->state->players[0]->resources->books->law);
        $this->assertSame([Faction::Philosophers->specialActionId()], $simulation->state->players[0]->usedSpecialActionIds);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    public function test_it_does_not_offer_bridge_special_actions_without_an_eligible_pair(): void
    {
        $state = new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Moles,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Bridge,
                resources: new PlayerResourcesData(tools: 1),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );

        $options = app(PlayerSpecialActionOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertSame([], $options);
    }
}
