<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Towns;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Data\PalaceWaterTownOptionData;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class PalaceWaterTownSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_accepting_a_water_town(): void
    {
        $state = $this->state();
        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof PalaceWaterTownOptionData,
        ));

        $this->assertCount(2, $options);
        $this->assertFalse($options[0]->accept);
        $this->assertTrue($options[1]->accept);
        $this->assertSame('water', $options[1]->waterHexId);
        $this->assertSame(GameActionOptionType::ResolvePalaceWaterTown, $options[1]->type());

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[1]);

        $this->assertSame(PendingInteractionType::OfferPalaceWaterTown, $state->pendingInteraction?->type);
        $this->assertSame(PendingInteractionType::ChooseTown, $simulation->state->pendingInteraction?->type);
        $this->assertSame(['land', 'water'], $simulation->state->pendingInteraction->context['townHexIds']);
        $this->assertSame('water', $simulation->state->pendingInteraction->context['markerHexId']);
        $this->assertSame([TownTile::Coins->value], $simulation->state->pendingInteraction->optionIds);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    public function test_it_simulates_declining_a_water_town(): void
    {
        $state = $this->state();
        $option = collect(app(GameActionOptionFinder::class)->execute($state, 1))->first(
            static fn ($option): bool => $option instanceof PalaceWaterTownOptionData && ! $option->accept,
        );

        $this->assertInstanceOf(PalaceWaterTownOptionData::class, $option);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);

        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
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
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableTownTileIds: [TownTile::Coins->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::OfferPalaceWaterTown,
                1,
                context: [
                    'townsByWaterHexId' => ['water' => ['land']],
                    'builtHexId' => 'land',
                    'queuedBuiltHexIds' => [],
                ],
            ),
        );
    }
}
