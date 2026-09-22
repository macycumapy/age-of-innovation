<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Data\WorkshopAfterTerraformingOptionData;
use App\Domain\Game\Enums\BuildingType;
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

class WorkshopAfterTerraformingSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_building_the_workshop(): void
    {
        $state = $this->state();
        $options = app(GameActionOptionFinder::class)->execute($state, 1);
        $buildOption = collect($options)->first(
            static fn ($option): bool => $option instanceof WorkshopAfterTerraformingOptionData && $option->build,
        );

        $this->assertCount(2, array_filter(
            $options,
            static fn ($option): bool => $option instanceof WorkshopAfterTerraformingOptionData,
        ));
        $this->assertInstanceOf(WorkshopAfterTerraformingOptionData::class, $buildOption);
        $this->assertSame(GameActionOptionType::ResolveWorkshopAfterTerraforming, $buildOption->type());

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $buildOption);

        $this->assertNull($state->board->hexes[0]->building);
        $this->assertSame(2, $state->players[0]->resources->tools);
        $this->assertSame(BuildingType::Workshop, $simulation->state->board->hexes[0]->building?->type);
        $this->assertSame(1, $simulation->state->players[0]->resources->tools);
        $this->assertSame(2, $simulation->state->players[0]->resources->coins);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
        $this->assertSame(10, $simulation->nextActiveUserId);
    }

    public function test_it_simulates_declining_the_workshop(): void
    {
        $state = $this->state();
        $declineOption = collect(app(GameActionOptionFinder::class)->execute($state, 1))->first(
            static fn ($option): bool => $option instanceof WorkshopAfterTerraformingOptionData && ! $option->build,
        );

        $this->assertInstanceOf(WorkshopAfterTerraformingOptionData::class, $declineOption);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $declineOption);

        $this->assertNull($simulation->state->board->hexes[0]->building);
        $this->assertSame(2, $simulation->state->players[0]->resources->tools);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
    }

    private function state(): GameStateData
    {
        return new GameStateData(
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
            )]),
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(coins: 4, tools: 2),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::BuildWorkshopAfterTerraforming,
                1,
                ['0:0'],
                ['toolCost' => 1, 'coinCost' => 2],
            ),
        );
    }
}
