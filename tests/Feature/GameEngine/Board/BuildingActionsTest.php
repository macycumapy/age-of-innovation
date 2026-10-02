<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Board;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class BuildingActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_can_build_a_workshop_on_reachable_homeland_using_navigation(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['0:1'],
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                ),
                new BoardHexStateData(
                    id: '0:1',
                    q: 0,
                    r: 1,
                    initialTerrain: TerrainType::Water,
                    terrain: TerrainType::Water,
                    adjacentHexIds: ['0:0', '0:2'],
                ),
                new BoardHexStateData(
                    id: '0:2',
                    q: 0,
                    r: 2,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['0:1'],
                ),
                ...array_map(
                    static fn (int $q): BoardHexStateData => new BoardHexStateData(
                        id: $q.':1',
                        q: $q,
                        r: 1,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        building: new BuildingStateData(BuildingType::Workshop, $player->id),
                    ),
                    range(1, 7),
                ),
                new BoardHexStateData(
                    id: '8:1',
                    q: 8,
                    r: 1,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(
                        BuildingType::Workshop,
                        $player->id,
                        isNeutral: true,
                    ),
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 1, coins: 2),
                shippingLevel: 1,
            )],
        )]);

        $this->actingAs($user)->post(route('games.workshop', $game), ['hex_id' => '0:2'])
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(BuildingType::Workshop, $game->state->board->hexes[2]->building?->type);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(GameActionType::BuildWorkshop, $game->actions()->sole()->type);

        $this->post(route('games.current-turn.restart', $game));
        $game->refresh();

        $this->assertNull($game->state->board->hexes[2]->building);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(2, $game->state->players[0]->resources->coins);
    }

    public function test_player_can_build_a_workshop_on_homeland_connected_by_their_bridge(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(
                hexes: [
                    new BoardHexStateData(
                        id: '0:0',
                        q: 0,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        building: new BuildingStateData(BuildingType::Workshop, $player->id),
                    ),
                    new BoardHexStateData(
                        id: '0:2',
                        q: 0,
                        r: 2,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                    ),
                ],
                bridges: [new BridgeStateData('0:0', '0:2', $player->id)],
            ),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 1, coins: 2),
            )],
        )]);

        $this->actingAs($user)->post(route('games.workshop', $game), ['hex_id' => '0:2'])
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(BuildingType::Workshop, $game->state->board->hexes[1]->building?->type);
        $this->assertSame($player->id, $game->state->board->hexes[1]->building?->ownerPlayerId);
    }

    public function test_player_can_upgrade_a_workshop_to_a_discounted_guild(): void
    {
        $user = User::factory()->create();
        $neighborUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $neighbor = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $neighborUser->id, 'seat' => 2]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id, $neighbor->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['0:0'],
                    building: new BuildingStateData(BuildingType::Workshop, $neighbor->id),
                ),
            ]),
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::GuildLaw->value,
            ),
            players: [
                new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::BuildGuild,
                    resources: new PlayerResourcesData(coins: 2, tools: 2),
                    palaceId: PalaceAbility::Palace13->value,
                ),
                new GamePlayerStateData(
                    playerId: $neighbor->id,
                    userId: $neighborUser->id,
                    color: PlayerColor::Red,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(power: new PowerBowlsStateData(bowlTwo: 1)),
                ),
            ],
        )]);

        $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where('game.data.buildingUpgrades', []),
            );
        $state = $game->state;
        $state->players[0]->resources->coins = 3;
        $game->update(['state' => $state]);

        $this->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => BuildingType::Guild->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(BuildingType::Guild, $game->state->board->hexes[0]->building?->type);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertSame(29, $game->state->players[0]->victoryPoints);
        $this->assertSame(PendingInteractionType::PowerOffer, $game->state->pendingInteraction?->type);
        $this->assertSame($neighborUser->id, $game->active_player_id);
        $this->assertSame(GameActionType::UpgradeBuilding, $game->actions()->sole()->type);
        $this->assertSame(9, $game->actions()->sole()->payload['victory_points']);
        $this->assertCount(3, $game->actions()->sole()->payload['scoring_sources']);
    }

    public function test_bridge_to_an_opponent_building_discounts_a_guild_upgrade(): void
    {
        $user = User::factory()->create();
        $neighborUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $neighbor = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $neighborUser->id, 'seat' => 2]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id, $neighbor->id],
            board: new BoardStateData(
                hexes: [
                    new BoardHexStateData(
                        id: '0:0',
                        q: 0,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        building: new BuildingStateData(BuildingType::Workshop, $player->id),
                    ),
                    new BoardHexStateData(
                        id: '1:0',
                        q: 1,
                        r: 0,
                        initialTerrain: TerrainType::Mountain,
                        terrain: TerrainType::Mountain,
                        building: new BuildingStateData(BuildingType::Workshop, $neighbor->id),
                    ),
                ],
                bridges: [
                    new BridgeStateData(
                        fromHexId: '0:0',
                        toHexId: '1:0',
                        ownerPlayerId: $neighbor->id,
                    ),
                ],
            ),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [
                new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(coins: 3, tools: 2),
                ),
                new GamePlayerStateData(
                    playerId: $neighbor->id,
                    userId: $neighborUser->id,
                    color: PlayerColor::Red,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlTwo: 1),
                    ),
                ),
            ],
        )]);

        $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.buildingUpgrades.0.hexId', '0:0')
                    ->where('game.data.buildingUpgrades.0.coins', 3),
            );

        $this->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => BuildingType::Guild->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(BuildingType::Guild, $game->state->board->hexes[0]->building?->type);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertSame(3, $game->actions()->sole()->payload['coins']);
        $this->assertSame(PendingInteractionType::PowerOffer, $game->state->pendingInteraction?->type);
        $this->assertSame($neighbor->id, $game->state->pendingInteraction?->playerId);
        $this->assertSame(1, $game->state->pendingInteraction?->context['powerAmount']);
        $this->assertSame($neighborUser->id, $game->active_player_id);
    }

    #[DataProvider('neutralBuildingSupplyProvider')]
    public function test_neutral_buildings_do_not_use_the_personal_upgrade_supply(
        BuildingType $source,
        BuildingType $target,
        int $personalTargetCount,
    ): void {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $targetBuildings = $personalTargetCount === 0 ? [] : array_map(
            static fn (int $index): BoardHexStateData => new BoardHexStateData(
                id: $index.':0',
                q: $index,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData($target, $player->id),
            ),
            range(1, $personalTargetCount),
        );
        $game->update(['state' => new GameStateData(
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData($source, $player->id),
                ),
                ...$targetBuildings,
                new BoardHexStateData(
                    id: '9:0',
                    q: 9,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData($target, $player->id, isNeutral: true),
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 10, coins: 10),
            )],
            availablePalaceIds: [PalaceAbility::Palace01->value],
        )]);

        $this->actingAs($user)->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => $target->value,
        ])->assertNoContent();
        $game->refresh();

        $upgradedBuilding = collect($game->state->board->hexes)->firstWhere('id', '0:0')?->building;
        $this->assertSame($target, $upgradedBuilding?->type);
        $this->assertFalse($upgradedBuilding?->isNeutral);
    }

    /** @return array<string, array{BuildingType, BuildingType, int}> */
    public static function neutralBuildingSupplyProvider(): array
    {
        return [
            'palace' => [BuildingType::Guild, BuildingType::Palace, 0],
            'school' => [BuildingType::Guild, BuildingType::School, 2],
            'university' => [BuildingType::School, BuildingType::University, 0],
        ];
    }

    public function test_monks_cannot_upgrade_a_school_when_their_university_is_already_on_the_map(): void
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
            'faction' => Faction::Monks,
        ]);
        $game->update(['state' => new GameStateData(
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::School, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::University, $player->id),
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Monks,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 10, coins: 10),
            )],
        )]);

        $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where('game.data.buildingUpgrades', []),
            );

        $this->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => BuildingType::University->value,
        ])->assertSessionHasErrors('building');

        $game->refresh();

        $this->assertSame(BuildingType::School, $game->state->board->hexes[0]->building?->type);
        $this->assertSame(10, $game->state->players[0]->resources->tools);
        $this->assertSame(10, $game->state->players[0]->resources->coins);
        $this->assertCount(0, $game->actions);
    }
}
