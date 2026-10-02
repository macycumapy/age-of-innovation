<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Board;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Data\PlaceBridgeOptionData;
use App\Domain\GameEngine\Board\Data\SkipBridgeOptionData;
use App\Domain\GameEngine\Board\Enums\BridgeSource;
use App\Domain\GameEngine\Board\Enums\BuildingType;
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
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class PlaceBridgeSimulatorTest extends TestCase
{
    private function bridgeState(): GameStateData
    {
        return new GameStateData(
            schemaVersion: 4,
            board: new BoardStateData(
                hexes: [
                    new BoardHexStateData(
                        id: '0:0',
                        q: 0,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        building: new BuildingStateData(BuildingType::Workshop, 1),
                    ),
                    new BoardHexStateData(
                        id: '1:1',
                        q: 1,
                        r: 1,
                        initialTerrain: TerrainType::Mountain,
                        terrain: TerrainType::Mountain,
                    ),
                    new BoardHexStateData(
                        id: '1:0',
                        q: 1,
                        r: 0,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                    new BoardHexStateData(
                        id: '0:1',
                        q: 0,
                        r: 1,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                    new BoardHexStateData(
                        id: '2:-1',
                        q: 2,
                        r: -1,
                        initialTerrain: TerrainType::Mountain,
                        terrain: TerrainType::Mountain,
                    ),
                    new BoardHexStateData(
                        id: '1:-1',
                        q: 1,
                        r: -1,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                ],
                riverBankHexIds: ['0:0', '1:1', '2:-1'],
            ),
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::PlaceBridge,
                1,
                [],
                ['source' => 'power'],
            ),
        );
    }

    public function test_it_enumerates_and_simulates_a_confirmed_bridge_placement(): void
    {
        $state = $this->bridgeState();
        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof PlaceBridgeOptionData,
        ));

        $this->assertCount(2, $options);
        $this->assertSame(GameActionOptionType::PlaceBridge, $options[0]->type());
        $this->assertSame('0:0', $options[0]->fromHexId);
        $this->assertSame('1:1', $options[0]->toHexId);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame([], $state->board->bridges);
        $this->assertCount(1, $simulation->state->board->bridges);
        $this->assertSame('0:0', $simulation->state->board->bridges[0]->fromHexId);
        $this->assertSame('1:1', $simulation->state->board->bridges[0]->toHexId);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);

        $palaceState = $state->deepCopy();
        $palaceState->pendingInteraction->context = [
            'source' => 'palace_15',
            'builtHexId' => '0:0',
        ];
        $palaceState->pendingInteractionQueue = [new PendingInteractionData(
            PendingInteractionType::PlaceBridge,
            1,
            context: ['builtHexId' => '0:0'],
        )];
        $skipOption = collect(app(GameActionOptionFinder::class)->execute($palaceState, 1))
            ->first(static fn ($option): bool => $option instanceof SkipBridgeOptionData);

        $this->assertInstanceOf(SkipBridgeOptionData::class, $skipOption);

        $firstSkip = app(GameActionSimulator::class)->execute($palaceState, 1, $skipOption);
        $secondSkipOption = collect(app(GameActionOptionFinder::class)->execute($firstSkip->state, 1))
            ->first(static fn ($option): bool => $option instanceof SkipBridgeOptionData);

        $this->assertInstanceOf(SkipBridgeOptionData::class, $secondSkipOption);

        $secondSkip = app(GameActionSimulator::class)->execute($firstSkip->state, 1, $secondSkipOption);

        $this->assertSame([], $secondSkip->state->board->bridges);
        $this->assertNull($secondSkip->state->pendingInteraction);

        $firstBridge = app(GameActionSimulator::class)->execute($palaceState, 1, $options[0]);

        $this->assertSame('palace_15', $firstBridge->state->pendingInteraction?->context['source']);
        $this->assertSame(BridgeSource::Palace15, BridgeSource::from($firstBridge->state->pendingInteraction->context['source']));
        $this->assertSame([], $firstBridge->state->pendingInteractionQueue);

        $secondOptions = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($firstBridge->state, 1),
            static fn ($option): bool => $option instanceof PlaceBridgeOptionData,
        ));

        $this->assertCount(1, $secondOptions);

        $secondBridge = app(GameActionSimulator::class)->execute($firstBridge->state, 1, $secondOptions[0]);

        $this->assertCount(2, $secondBridge->state->board->bridges);
        $this->assertNull($secondBridge->state->pendingInteraction);
    }

    public function test_bridge_sources_are_created_by_domain_actions_and_can_be_simulated(): void
    {
        foreach (['power', 'round_bonus', 'faction'] as $source) {
            $state = $this->bridgeState();
            $state->pendingInteraction = null;
            $player = $state->players[0];
            if ($source === 'power') {
                $player->resources->power->bowlThree = 6;
                app(\App\Domain\GameEngine\Economy\Actions\ApplyPowerActionAction::class)->execute(
                    $state,
                    $player,
                    \App\Domain\GameEngine\Economy\Enums\PowerAction::BuildBridge,
                    0,
                );
            } elseif ($source === 'round_bonus') {
                $player->roundBonus = RoundBonus::Bridge;
                app(\App\Domain\GameEngine\PlayerAbilities\Actions\ApplyRoundBonusAction::class)->execute($state, $player, null);
            } else {
                $player->faction = Faction::Moles;
                $player->resources->tools = 1;
                $state->board->hexes[2]->terrain = TerrainType::Forest;
                app(\App\Domain\GameEngine\PlayerAbilities\Actions\ApplyFactionAction::class)->execute($state, $player, null);
            }
            $this->assertSame($source, $state->pendingInteraction?->context['source']);
            $this->assertSame($source, BridgeSource::from($source)->value);
            $options = array_values(array_filter(
                app(GameActionOptionFinder::class)->execute($state, 1),
                static fn ($option): bool => $option instanceof PlaceBridgeOptionData,
            ));
            $this->assertNotEmpty($options);
            foreach ($options as $option) {
                $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);
                $this->assertCount(1, $simulation->state->board->bridges);
            }
            $this->assertSame([], $state->board->bridges);
        }
    }

    public function test_no_bridge_places_produce_no_placement_options_but_allow_skipping_palace_reward(): void
    {
        $state = $this->bridgeState();
        $state->board->riverBankHexIds = [];
        $this->assertSame([], app(GameActionOptionFinder::class)->execute($state, 1));
        $state->pendingInteraction->context['source'] = 'palace_15';
        $options = app(GameActionOptionFinder::class)->execute($state, 1);
        $this->assertCount(1, $options);
        $this->assertInstanceOf(SkipBridgeOptionData::class, $options[0]);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame([], $simulation->state->board->bridges);
    }
}
