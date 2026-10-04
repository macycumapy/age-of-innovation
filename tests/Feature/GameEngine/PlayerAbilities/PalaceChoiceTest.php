<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\PlayerAbilities;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
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

class PalaceChoiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_palace_five_grants_a_free_competency(): void
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
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Palace, $player->id),
            )]),
            round: new RoundStateData(phase: GamePhase::Actions, hasTakenMainAction: true),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 0, coins: 0),
            )],
            availablePalaceIds: [PalaceAbility::Palace05->value],
            availableCompetencyIds: [Competency::Competency04->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChoosePalace,
                $player->id,
                [PalaceAbility::Palace05->value],
                ['builtHexId' => '0:0'],
            ),
        )]);

        $this->actingAs($user)->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace05->value,
        ])->assertNoContent();
        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseCompetency, $game->state->pendingInteraction->type);
        $this->assertSame([Competency::Competency04->value], $game->state->pendingInteraction->optionIds);
        $this->assertSame('0:0', $game->state->pendingInteraction->context['builtHexId']);
        $this->assertSame([], $game->state->players[0]->competencyIds);

        $this->post(route('games.rewards', $game), [
            'competency_id' => Competency::Competency01->value,
        ])->assertSessionHasErrors('competency_id');
        $this->post(route('games.rewards', $game), [
            'competency_id' => Competency::Competency04->value,
        ])->assertNoContent();
        $game->refresh();
        $this->assertSame([Competency::Competency04->value], $game->state->players[0]->competencyIds);
        $this->assertSame([], $game->state->availableCompetencyIds);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(2, $game->state->players[0]->resources->coins);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
    }

    public function test_player_chooses_an_available_palace_tile_after_building_a_palace(): void
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
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Guild, $player->id),
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
                resources: new PlayerResourcesData(tools: 4, coins: 6),
            )],
            availablePalaceIds: [
                PalaceAbility::Palace01->value,
                PalaceAbility::Palace17->value,
            ],
        )]);

        $this->actingAs($user)->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => BuildingType::Palace->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChoosePalace, $game->state->pendingInteraction?->type);
        $this->assertSame([
            'reason' => 'building',
            'builtHexId' => '0:0',
        ], $game->state->pendingInteraction?->context);
        $this->assertSame([
            PalaceAbility::Palace01->value,
            PalaceAbility::Palace17->value,
        ], $game->state->pendingInteraction?->optionIds);
        $this->assertNull($game->state->players[0]->palaceId);

        $this->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace02->value,
        ])->assertSessionHasErrors('palace_id');

        $this->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace17->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(PalaceAbility::Palace17->value, $game->state->players[0]->palaceId);
        $this->assertSame(30, $game->state->players[0]->victoryPoints);
        $this->assertSame([PalaceAbility::Palace01->value], $game->state->availablePalaceIds);
        $this->assertNull($game->state->pendingInteraction);
        $this->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where(
                    'game.data.playerBoardStates.0.palaceId',
                    PalaceAbility::Palace17->value,
                ),
            );
        $this->assertSame([
            GameActionType::UpgradeBuilding,
            GameActionType::ChoosePalace,
        ], $game->actions()->orderBy('sequence')->pluck('type')->all());
    }

    public function test_palace_ten_grants_power_and_books_when_chosen(): void
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
        ]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Palace, $player->id),
            )]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(
                    power: new PowerBowlsStateData(bowlOne: 6, bowlTwo: 6),
                ),
            )],
            availablePalaceIds: [PalaceAbility::Palace10->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChoosePalace,
                $player->id,
                [PalaceAbility::Palace10->value],
                ['reason' => 'building', 'builtHexId' => '0:0'],
            ),
        )]);

        $this->actingAs($user)->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace10->value,
        ])->assertNoContent();

        $game->refresh();
        $playerState = $game->state->players[0];
        $this->assertSame(0, $playerState->resources->power->bowlOne);
        $this->assertSame(6, $playerState->resources->power->bowlTwo);
        $this->assertSame(6, $playerState->resources->power->bowlThree);
        $this->assertSame(2, $playerState->resources->books->unassigned);
        $this->assertSame(12, $game->actions()->sole()->payload['gained_power']);
        $this->assertSame(2, $game->actions()->sole()->payload['gained_books']);
        $this->assertSame(PendingInteractionType::ChoosePalaceBooks, $game->state->pendingInteraction?->type);

        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 1, 'law' => 1, 'engineering' => 0, 'medicine' => 0],
        ])->assertNoContent();
        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(1, $game->state->players[0]->resources->books->law);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);
    }

    public function test_palace_fourteen_advances_shipping_twice_and_grants_track_rewards_when_chosen(): void
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
        ]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Palace, $player->id),
            )]),
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::TrackEngineering->value,
            ),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
            )],
            availablePalaceIds: [PalaceAbility::Palace14->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChoosePalace,
                $player->id,
                [PalaceAbility::Palace14->value],
                ['reason' => 'building', 'builtHexId' => '0:0'],
            ),
        )]);

        $this->actingAs($user)->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace14->value,
        ])->assertNoContent();

        $game->refresh();
        $playerState = $game->state->players[0];
        $this->assertSame(2, $playerState->shippingLevel);
        $this->assertSame(28, $playerState->victoryPoints);
        $this->assertSame(2, $playerState->resources->books->unassigned);
        $this->assertSame(PendingInteractionType::ChoosePalaceBooks, $game->state->pendingInteraction?->type);
        $this->assertEquals([
            'steps' => 2,
            'books' => 2,
            'victoryPoints' => 8,
        ], $game->actions()->sole()->payload['shipping_reward']);

        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 1, 'medicine' => 0],
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(1, $game->state->players[0]->resources->books->engineering);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);
        $this->assertNull($game->state->pendingInteraction);
    }

    public function test_palace_fifteen_grants_spades_and_books_when_chosen(): void
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
        ]);
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
                        building: new BuildingStateData(BuildingType::Palace, $player->id),
                        adjacentHexIds: ['0:-1'],
                    ),
                    new BoardHexStateData(
                        id: '1:1',
                        q: 1,
                        r: 1,
                        initialTerrain: TerrainType::Mountain,
                        terrain: TerrainType::Mountain,
                        adjacentHexIds: ['0:0'],
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
                        id: '0:-1',
                        q: 0,
                        r: -1,
                        initialTerrain: TerrainType::Mountain,
                        terrain: TerrainType::Mountain,
                    ),
                ],
                riverBankHexIds: ['0:0', '1:1'],
            ),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
            )],
            availablePalaceIds: [PalaceAbility::Palace15->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChoosePalace,
                $player->id,
                [PalaceAbility::Palace15->value],
                ['reason' => 'building', 'builtHexId' => '0:0'],
            ),
        )]);

        $this->actingAs($user)->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace15->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->unassignedSpades);
        $this->assertSame(2, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(2, $game->actions()->sole()->payload['gained_spades']);
        $this->assertSame(2, $game->actions()->sole()->payload['gained_books']);
        $this->assertSame(PendingInteractionType::ChoosePalaceBooks, $game->state->pendingInteraction?->type);

        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 0, 'law' => 0, 'engineering' => 1, 'medicine' => 1],
        ])->assertNoContent();
        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->books->engineering);
        $this->assertSame(1, $game->state->players[0]->resources->books->medicine);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);
        $this->assertSame([
            PendingInteractionType::PlaceBridge,
            PendingInteractionType::PlaceBridge,
        ], array_map(
            static fn (PendingInteractionData $interaction): PendingInteractionType => $interaction->type,
            $game->state->pendingInteractionQueue,
        ));
    }

    public function test_palace_sixteen_places_a_free_guild_on_any_empty_homeland_hex(): void
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
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Guild, $player->id),
                ),
                new BoardHexStateData(
                    id: '5:5',
                    q: 5,
                    r: 5,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Desert,
                    terrain: TerrainType::Desert,
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
                resources: new PlayerResourcesData(tools: 4, coins: 6),
            )],
            availablePalaceIds: [PalaceAbility::Palace16->value],
        )]);

        $this->actingAs($user)->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => BuildingType::Palace->value,
        ]);
        $this->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace16->value,
        ]);

        $game->refresh();
        $this->assertSame(PendingInteractionType::PlacePalaceGuild, $game->state->pendingInteraction?->type);
        $this->assertSame(['5:5'], $game->state->pendingInteraction?->optionIds);

        $this->post(route('games.palace-guild.store', $game), ['hex_id' => '1:0'])
            ->assertSessionHasErrors('hex_id');
        $this->post(route('games.palace-guild.store', $game), ['hex_id' => '5:5'])
            ->assertNoContent();

        $game->refresh();
        $this->assertSame('5:5', $game->state->pendingInteraction?->context['selectedHexId']);
        $this->assertSame(BuildingType::Guild, collect($game->state->board->hexes)->firstWhere('id', '5:5')?->building?->type);

        $this->delete(route('games.palace-guild.destroy', $game))
            ->assertNoContent();
        $game->refresh();
        $this->assertNull(collect($game->state->board->hexes)->firstWhere('id', '5:5')?->building);
        $this->assertNull($game->state->pendingInteraction?->context['selectedHexId']);

        $this->post(route('games.palace-guild.store', $game), ['hex_id' => '5:5']);
        $this->post(route('games.palace-guild.confirm', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(BuildingType::Guild, collect($game->state->board->hexes)->firstWhere('id', '5:5')?->building?->type);
        $this->assertSame(GameActionType::PlacePalaceGuild, $game->actions()->latest('sequence')->firstOrFail()->type);
    }

    public function test_palace_eleven_grants_a_free_town_tile_after_it_is_built(): void
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
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Palace, $player->id),
            )]),
            round: new RoundStateData(phase: GamePhase::Actions, hasTakenMainAction: true),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
            )],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChoosePalace,
                $player->id,
                [PalaceAbility::Palace11->value],
                ['reason' => 'building', 'builtHexId' => '0:0'],
            ),
            availablePalaceIds: [PalaceAbility::Palace11->value],
            availableTownTileIds: [TownTile::Tools->value],
        )]);

        $this->actingAs($user)->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace11->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseTown, $game->state->pendingInteraction?->type);
        $this->assertSame([TownTile::Tools->value], $game->state->pendingInteraction?->optionIds);
        $this->assertTrue($game->state->pendingInteraction?->context['freePalaceTownTile']);

        $this->post(route('games.town', $game), ['town_tile' => TownTile::Tools->value])
            ->assertNoContent();

        $game->refresh();
        $this->assertSame([TownTile::Tools->value], $game->state->players[0]->townTileIds);
        $this->assertSame(3, $game->state->players[0]->resources->tools);
        $this->assertNull($game->state->board->hexes[0]->townId);
        $this->assertNull($game->state->pendingInteraction);
    }
}
