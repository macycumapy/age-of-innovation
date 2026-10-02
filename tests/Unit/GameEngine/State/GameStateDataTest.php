<?php

declare(strict_types=1);

namespace Tests\Unit\GameEngine\State;

use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Setup\Data\PlanningBundleData;
use App\Domain\GameEngine\Setup\Data\PlayerPlanningSelectionData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class GameStateDataTest extends TestCase
{
    public function test_it_restores_nested_domain_types_without_changing_the_serialized_snapshot(): void
    {
        $state = GameStateData::from([
            'board' => ['bridges' => [['fromHexId' => '0:0', 'toHexId' => '1:0', 'ownerPlayerId' => 10]]],
            'players' => [[
                'playerId' => 10,
                'userId' => 20,
                'color' => PlayerColor::Yellow->value,
                'faction' => Faction::Inventors->value,
                'homeland' => TerrainType::Desert->value,
                'roundBonus' => RoundBonus::Coins->value,
                'resources' => ['coins' => 7, 'books' => ['law' => 2], 'power' => ['bowlThree' => 3]],
                'knowledge' => ['law' => 4],
            ]],
            'planningSelections' => [[
                'playerId' => 10,
                'bundle' => [
                    'homeland' => TerrainType::Desert->value,
                    'faction' => Faction::Inventors->value,
                    'roundBonus' => RoundBonus::Coins->value,
                ],
            ]],
            'pendingInteraction' => ['type' => PendingInteractionType::PlaceBridge->value, 'playerId' => 10],
            'pendingInteractionQueue' => [[
                'type' => PendingInteractionType::ChooseTown->value,
                'playerId' => 10,
                'optionIds' => ['town_1'],
                'context' => ['builtHexId' => '0:0'],
            ]],
        ]);
        $snapshot = $state->toArray();

        $restored = GameStateData::from($snapshot);

        $this->assertSame($snapshot, $restored->toArray());
        $this->assertInstanceOf(BoardStateData::class, $restored->board);
        $this->assertInstanceOf(BridgeStateData::class, $restored->board->bridges[0]);
        $this->assertInstanceOf(PlayerResourcesData::class, $restored->players[0]->resources);
        $this->assertInstanceOf(BookSupplyData::class, $restored->players[0]->resources->books);
        $this->assertInstanceOf(PowerBowlsStateData::class, $restored->players[0]->resources->power);
        $this->assertInstanceOf(KnowledgeStateData::class, $restored->players[0]->knowledge);
        $this->assertInstanceOf(PlayerPlanningSelectionData::class, $restored->planningSelections[0]);
        $this->assertInstanceOf(PlanningBundleData::class, $restored->planningSelections[0]->bundle);
        $this->assertInstanceOf(PendingInteractionData::class, $restored->pendingInteraction);
        $this->assertInstanceOf(PendingInteractionData::class, $restored->pendingInteractionQueue[0]);
    }

    public function test_it_creates_a_typed_game_snapshot_from_an_array(): void
    {
        $state = GameStateData::from([
            'turnOrder' => [10],
            'board' => [
                'variant' => MapVariant::ThreeToFivePlayers->value,
                'hexes' => [[
                    'id' => '0:0',
                    'q' => 0,
                    'r' => 0,
                    'initialTerrain' => TerrainType::Desert->value,
                    'terrain' => TerrainType::Desert->value,
                    'adjacentHexIds' => ['1:0'],
                    'riverConnectedHexIds' => [],
                ]],
            ],
            'players' => [[
                'playerId' => 10,
                'userId' => 20,
                'color' => PlayerColor::Yellow->value,
                'faction' => Faction::Inventors->value,
                'homeland' => TerrainType::Desert->value,
                'roundBonus' => RoundBonus::Coins->value,
            ]],
            'round' => [
                'number' => 2,
                'phase' => GamePhase::Actions->value,
            ],
        ]);

        $this->assertSame([10], $state->turnOrder);
        $this->assertSame(MapVariant::ThreeToFivePlayers, $state->board->variant);
        $this->assertSame(TerrainType::Desert, $state->board->hexes[0]->initialTerrain);
        $this->assertSame(TerrainType::Desert, $state->board->hexes[0]->terrain);
        $this->assertSame(PlayerColor::Yellow, $state->players[0]->color);
        $this->assertSame(Faction::Inventors, $state->players[0]->faction);
        $this->assertSame(TerrainType::Desert, $state->players[0]->homeland);
        $this->assertSame(RoundBonus::Coins, $state->players[0]->roundBonus);
        $this->assertSame(GamePhase::Actions, $state->round->phase);
    }
}
