<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\PalaceActionOptionFinder;
use Tests\TestCase;

class PalaceActionSimulatorTest extends TestCase
{
    public function test_it_enumerates_books_and_simulates_palace_thirteen_without_mutating_source(): void
    {
        $state = new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                palaceId: PalaceAbility::Palace13->value,
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
        $options = app(PalaceActionOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertCount(4, $options);
        $lawOption = collect($options)->firstWhere('discipline', KnowledgeDiscipline::Law);
        $this->assertNotNull($lawOption);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $lawOption);

        $this->assertSame(0, $state->players[0]->resources->coins);
        $this->assertSame(0, $state->players[0]->resources->books->law);
        $this->assertSame(3, $simulation->state->players[0]->resources->coins);
        $this->assertSame(1, $simulation->state->players[0]->resources->books->law);
        $this->assertSame([PalaceAbility::Palace13->specialActionId()], $simulation->state->players[0]->usedSpecialActionIds);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }
}
