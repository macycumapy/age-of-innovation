<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\PlayerSpecialActionOptionFinder;
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
}
