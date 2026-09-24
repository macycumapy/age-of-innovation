<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\ChoosePalaceOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TownTile;
use App\Domain\Game\Services\GameActionOptionFinder;
use App\Domain\Game\Services\GameActionSimulator;
use Tests\TestCase;

class ChoosePalaceSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_a_palace_with_books(): void
    {
        $state = $this->state();
        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof ChoosePalaceOptionData,
        ));

        $this->assertCount(2, $options);
        $this->assertSame(PalaceAbility::Palace10, $options[0]->palace);
        $this->assertSame(GameActionOptionType::ChoosePalace, $options[0]->type());

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertNull($state->players[0]->palaceId);
        $this->assertSame(PalaceAbility::Palace10->value, $simulation->state->players[0]->palaceId);
        $this->assertSame(2, $simulation->state->players[0]->resources->books->unassigned);
        $this->assertSame(0, $simulation->state->players[0]->resources->power->bowlOne);
        $this->assertSame(4, $simulation->state->players[0]->resources->power->bowlThree);
        $this->assertSame(PendingInteractionType::ChoosePalaceBooks, $simulation->state->pendingInteraction?->type);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    public function test_free_town_palace_creates_a_town_choice(): void
    {
        $state = $this->state();
        $option = collect(app(GameActionOptionFinder::class)->execute($state, 1))->first(
            static fn ($option): bool => $option instanceof ChoosePalaceOptionData
                && $option->palace === PalaceAbility::Palace11,
        );

        $this->assertInstanceOf(ChoosePalaceOptionData::class, $option);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);

        $this->assertSame(PendingInteractionType::ChooseTown, $simulation->state->pendingInteraction?->type);
        $this->assertTrue($simulation->state->pendingInteraction->context['freePalaceTownTile']);
    }

    private function state(): GameStateData
    {
        return new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(power: new PowerBowlsStateData(bowlOne: 2, bowlTwo: 2)),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availablePalaceIds: [PalaceAbility::Palace10->value, PalaceAbility::Palace11->value],
            availableTownTileIds: [TownTile::Coins->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChoosePalace,
                1,
                [PalaceAbility::Palace10->value, PalaceAbility::Palace11->value],
                ['builtHexId' => '0:0'],
            ),
        );
    }
}
