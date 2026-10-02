<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Board;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Actions\PerformPowerActionAction;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\History\Actions\ReplayGameHistoryAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BridgeActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_power_bridge_can_be_selected_rolled_back_and_confirmed(): void
    {
        [$game, $user] = $this->gameForBridgeAction();

        $this->actingAs($user)->post(route('games.power-action', $game), [
            'action' => PowerAction::BuildBridge->value,
            'sacrifice_amount' => 0,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::PlaceBridge, $game->state->pendingInteraction?->type);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlThree);

        $state = $game->state;
        $state->pendingInteraction->context['pairs'] = [[
            'fromHexId' => '8:5',
            'toHexId' => '6:7',
        ], [
            'fromHexId' => '8:5',
            'toHexId' => '8:7',
        ]];
        $game->update(['state' => $state]);

        $this->post(route('games.bridge.store', $game), [
            'from_hex_id' => '0:0',
            'to_hex_id' => '3:0',
        ])->assertSessionHasErrors('bridge');
        $game->refresh();
        $this->assertCount(0, $game->state->board->bridges);

        $bridge = ['from_hex_id' => '8:5', 'to_hex_id' => '7:7'];
        $this->post(route('games.bridge.store', $game), $bridge)
            ->assertNoContent();
        $game->refresh();
        $this->assertSame('8:5', $game->state->pendingInteraction?->context['selectedFromHexId']);

        $this->delete(route('games.bridge.destroy', $game))
            ->assertNoContent();
        $game->refresh();
        $this->assertArrayNotHasKey('selectedFromHexId', $game->state->pendingInteraction?->context ?? []);

        $this->post(route('games.bridge.store', $game), $bridge);
        $this->post(route('games.bridge.confirm', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertCount(1, $game->state->board->bridges);
        $this->assertSame('8:5', $game->state->board->bridges[0]->fromHexId);
        $this->assertSame('7:7', $game->state->board->bridges[0]->toHexId);
        $this->assertSame(
            [GameActionType::PowerAction],
            $game->actions()->orderBy('sequence')->pluck('type')->all(),
        );
    }

    public function test_palace_fifteen_bridges_can_be_skipped_independently(): void
    {
        [$game, $user] = $this->gameForBridgeAction();
        $player = $game->players()->whereBelongsTo($user)->firstOrFail();
        $game = app(PerformPowerActionAction::class)->execute($game, $player, PowerAction::BuildBridge, 0);
        $state = $game->state;
        $this->assertNotNull($state->pendingInteraction);
        $state->pendingInteraction->context = ['source' => 'palace_15', 'builtHexId' => '8:5'];
        $state->pendingInteractionQueue = [new PendingInteractionData(
            PendingInteractionType::PlaceBridge,
            $player->id,
            context: ['builtHexId' => '8:5'],
        )];
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.bridge.skip', $game))->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::PlaceBridge, $game->state->pendingInteraction?->type);
        $this->assertSame([], $game->state->pendingInteractionQueue);
        $this->assertSame([], $game->state->board->bridges);

        $this->post(route('games.bridge.skip', $game))->assertNoContent();

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame([], $game->state->board->bridges);
    }

    public function test_moles_can_pay_a_tool_to_build_a_bridge_across_terrain(): void
    {
        [$game, $user] = $this->gameForBridgeAction();
        $state = $game->state;
        $state->players[0]->faction = Faction::Moles;
        $state->players[0]->resources->tools = 1;
        $state->board->riverBankHexIds = [];
        $state->board->hexes[1]->terrain = TerrainType::Plains;
        $state->board->hexes[2]->terrain = TerrainType::Forest;
        $state->board->hexes[3]->terrain = TerrainType::Forest;
        $state->board->hexes[3]->building = new BuildingStateData(BuildingType::Guild, $state->players[0]->playerId);
        $state->board->hexes[0]->adjacentHexIds = ['9:5', '9:4'];
        $state->board->hexes[] = new BoardHexStateData(
            id: '9:5',
            q: 9,
            r: 5,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            adjacentHexIds: ['8:5'],
            building: new BuildingStateData(BuildingType::Palace, $state->players[0]->playerId),
        );
        $state->board->hexes[] = new BoardHexStateData(
            id: '9:4',
            q: 9,
            r: 4,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            adjacentHexIds: ['8:5'],
            building: new BuildingStateData(BuildingType::Workshop, $state->players[0]->playerId),
        );
        $state->availableTownTileIds = [TownTile::Books->value];
        $game->update(['state' => $state]);
        $game->actions()->create([
            'sequence' => 1,
            'player_id' => null,
            'type' => GameActionType::PhaseCheckpoint,
            'payload' => [
                'phase' => GamePhase::Actions->value,
                'game' => [
                    'status' => $game->status->value,
                    'round' => $game->round,
                    'phase' => $game->phase->value,
                    'active_player_id' => $game->active_player_id,
                    'version' => $game->version,
                    'state' => $state->toArray(),
                    'started_at' => null,
                    'finished_at' => null,
                ],
                'players' => [[
                    'id' => $game->players()->sole()->id,
                    'color' => null,
                    'faction' => null,
                    'homeland' => null,
                    'is_ready' => false,
                    'result_place' => null,
                    'final_score' => null,
                ]],
                'final_scoring' => [],
            ],
            'events' => [],
            'state_version_before' => $game->version,
            'state_version_after' => $game->version,
        ]);

        $this->actingAs($user)->post(route('games.faction-action', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(PendingInteractionType::PlaceBridge, $game->state->pendingInteraction?->type);
        $this->assertContains([
            'toHexId' => '7:7',
            'fromHexId' => '8:5',
        ], $game->state->pendingInteraction?->context['pairs'] ?? []);
        $this->assertContains([
            'toHexId' => '8:5',
            'fromHexId' => '7:7',
        ], $game->state->pendingInteraction?->context['pairs'] ?? []);

        $this->post(route('games.bridge.store', $game), [
            'from_hex_id' => '7:7',
            'to_hex_id' => '8:5',
        ])->assertNoContent();
        $this->post(route('games.bridge.confirm', $game))->assertNoContent();

        $game->refresh();
        $this->assertCount(1, $game->state->board->bridges);
        $this->assertSame(PendingInteractionType::ChooseTown, $game->state->pendingInteraction?->type);
        $this->assertEqualsCanonicalizing(
            ['8:5', '9:5', '9:4', '7:7'],
            $game->state->pendingInteraction?->context['townHexIds'] ?? [],
        );
        $bridgeAction = $game->actions()->where('type', GameActionType::SpecialAction)->sole();
        $this->assertSame(Faction::Moles->value, $bridgeAction->payload['faction']);
        $this->assertSame(GameActionType::SpecialAction, $bridgeAction->type);

        $this->post(route('games.town', $game), ['town_tile' => TownTile::Books->value])->assertNoContent();
        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 1, 'law' => 1, 'engineering' => 0, 'medicine' => 0],
        ])->assertNoContent();

        app(ReplayGameHistoryAction::class)->execute($game, $game->actions()->orderBy('sequence')->get());
        $game->refresh();

        $this->assertNull($game->state->pendingInteraction);
        $this->assertNotNull($game->state->round->turnStartVersion);
        $this->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page
                ->where('game.data.canRestartCurrentTurn', true)
                ->where('game.data.canFinishCurrentTurn', true),
        );
    }

    public function test_moles_power_bridge_still_requires_a_river(): void
    {
        [$game, $user] = $this->gameForBridgeAction();
        $state = $game->state;
        $state->players[0]->faction = Faction::Moles;
        $state->board->riverBankHexIds = [];
        $state->board->hexes[1]->terrain = TerrainType::Plains;
        $state->board->hexes[2]->terrain = TerrainType::Forest;
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.power-action', $game), [
            'action' => PowerAction::BuildBridge->value,
            'sacrifice_amount' => 0,
        ])->assertSessionHasErrors('bridge');

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertCount(0, $game->state->board->bridges);
    }

    public function test_moles_faction_bridge_cannot_be_built_across_only_water(): void
    {
        [$game, $user] = $this->gameForBridgeAction();
        $state = $game->state;
        $state->players[0]->faction = Faction::Moles;
        $state->players[0]->resources->tools = 1;
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.faction-action', $game))
            ->assertSessionHasErrors('bridge');

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertFalse($game->state->round->hasTakenMainAction);
        $this->assertNull($game->state->pendingInteraction);
    }

    /** @return array{Game, User} */
    private function gameForBridgeAction(RoundBonus $roundBonus = RoundBonus::Coins): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                board: new BoardStateData(hexes: [
                    new BoardHexStateData(
                        id: '8:5',
                        q: 8,
                        r: 5,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        riverConnectedHexIds: ['6:7', '8:7'],
                        building: new BuildingStateData(BuildingType::Workshop, $player->id),
                    ),
                    new BoardHexStateData(
                        id: '8:6',
                        q: 8,
                        r: 6,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                    new BoardHexStateData(
                        id: '7:6',
                        q: 7,
                        r: 6,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                    new BoardHexStateData(
                        id: '7:7',
                        q: 7,
                        r: 7,
                        initialTerrain: TerrainType::Plains,
                        terrain: TerrainType::Plains,
                        riverConnectedHexIds: ['8:5'],
                    ),
                ], riverBankHexIds: ['8:5', '7:7']),
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: $roundBonus,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlThree: 3),
                    ),
                )],
            ),
        ]);

        return [$game, $user];
    }
}
