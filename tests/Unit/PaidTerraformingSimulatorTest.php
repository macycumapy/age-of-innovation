<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\PaidTerraformingOptionFinder;
use Tests\TestCase;

class PaidTerraformingSimulatorTest extends TestCase
{
    public function test_it_preserves_target_order_and_recalculates_costs_after_state_changes(): void
    {
        $state = $this->state(TerrainType::Mountain, tools: 9);
        $state->board->hexes[0]->adjacentHexIds = ['2:0', 'missing', '1:0', '2:0'];
        $state->board->hexes[] = new BoardHexStateData(
            id: '2:0',
            q: 2,
            r: 0,
            initialTerrain: TerrainType::Desert,
            terrain: TerrainType::Desert,
        );
        $finder = app(PaidTerraformingOptionFinder::class);
        $options = $finder->execute($state, $state->players[0]);

        $this->assertSame(['2:0', '1:0'], array_column($options, 'hexId'));
        $this->assertSame([9, 3], array_column($options, 'toolCost'));
        $state->players[0]->terraformingLevel = 2;
        $state->players[0]->resources->tools = 3;
        $options = $finder->execute($state, $state->players[0]);
        $this->assertSame(['2:0', '1:0'], array_column($options, 'hexId'));
        $this->assertSame([3, 1], array_column($options, 'toolCost'));

        $state->board->hexes[2]->building = new BuildingStateData(BuildingType::Workshop, 2);
        $this->assertSame(['1:0'], array_column($finder->execute($state, $state->players[0]), 'hexId'));
    }

    public function test_it_generates_and_simulates_paid_terraforming_without_mutating_the_source(): void
    {
        $state = $this->state(TerrainType::Mountain, tools: 3);
        $options = app(PaidTerraformingOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertCount(1, $options);
        $this->assertSame('1:0', $options[0]->hexId);
        $this->assertFalse($options[0]->useAvailable);
        $this->assertSame(3, $options[0]->toolCost);
        $this->assertSame(1, $options[0]->spadeCount);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(3, $state->players[0]->resources->tools);
        $this->assertNull($state->pendingInteraction);
        $this->assertFalse($state->round->hasTakenMainAction);
        $this->assertSame(0, $simulation->state->players[0]->resources->tools);
        $this->assertSame(0, $simulation->state->players[0]->unassignedSpades);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
        $this->assertSame(TerrainType::Forest, $simulation->state->board->hexes[1]->terrain);
        $this->assertSame(PendingInteractionType::BuildWorkshopAfterTerraforming, $simulation->state->pendingInteraction->type);
        $this->assertSame(['1:0'], $simulation->state->pendingInteraction->optionIds);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    public function test_it_can_simulate_spending_only_available_spades_during_an_existing_interaction(): void
    {
        $state = $this->state(TerrainType::Desert, tools: 0, unassignedSpades: 1);
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            1,
            ['1:0'],
            [
                'phase' => GamePhase::Actions->value,
                'remainingSpades' => 1,
                'targetTerrain' => TerrainType::Forest->value,
            ],
        );
        $options = app(PaidTerraformingOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertCount(1, $options);
        $this->assertTrue($options[0]->useAvailable);
        $this->assertSame(0, $options[0]->toolCost);
        $this->assertSame(3, $options[0]->spadeCount);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(['1:0'], $state->pendingInteraction->optionIds);
        $this->assertArrayNotHasKey('spadesToSpend', $state->pendingInteraction->context);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(0, $simulation->state->players[0]->unassignedSpades);
        $this->assertNotSame(TerrainType::Desert, $simulation->state->board->hexes[1]->terrain);
        $this->assertSame(0, $simulation->state->players[0]->resources->tools);
    }

    private function state(TerrainType $targetTerrain, int $tools, int $unassignedSpades = 0): GameStateData
    {
        return new GameStateData(
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Workshop, 1),
                    adjacentHexIds: ['1:0'],
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: $targetTerrain,
                    terrain: $targetTerrain,
                    adjacentHexIds: ['0:0'],
                ),
            ]),
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: $tools),
                unassignedSpades: $unassignedSpades,
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
    }
}
