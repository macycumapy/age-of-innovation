<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\MapVariant;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\BoardStateFactory;
use App\Models\Builders\GameBuilder;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GameManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_uses_custom_builder(): void
    {
        $this->assertInstanceOf(GameBuilder::class, Game::query());
    }

    public function test_guest_cannot_view_or_create_games(): void
    {
        $this->get(route('games.index'))->assertRedirect(route('login'));
        $this->post(route('games.store'))->assertRedirect(route('login'));
    }

    public function test_user_sees_open_lobbies_and_their_own_games(): void
    {
        $user = User::factory()->create();
        $openGame = Game::factory()->create();
        $ownGame = Game::factory()->create();
        Game::factory()->active()->create();

        GamePlayer::factory()->create([
            'game_id' => $ownGame->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('games.index'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                ->component('games/Index')
                ->has('games.data', 2)
                ->where('games.data.0.id', $ownGame->id)
                ->where('games.data.0.status', 'lobby')
                ->where('games.data.0.mapVariant', MapVariant::ThreeToFivePlayers->value)
                ->where('games.data.0.playersCount', 1)
                ->where('games.data.0.isJoined', true)
                ->has('games.data.0.createdAt')
                ->where('games.data.1.id', $openGame->id)
                ->where('games.data.1.isJoined', false)
                ->missing('games.data.2')
            );
    }

    public function test_user_can_open_game_preparation_page_and_join(): void
    {
        $owner = User::factory()->create();
        $joiningUser = User::factory()->create();
        $game = Game::factory()->create();

        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);

        $this->actingAs($joiningUser)
            ->get(route('games.show', $game))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('games/Show')
                    ->where('game.data.id', $game->id)
                    ->where('game.data.board.variant', MapVariant::ThreeToFivePlayers->value)
                    ->has('game.data.board.hexes')
                    ->where('game.data.isJoined', false)
                ->where('game.data.playersCount', 1)
                ->where('game.data.players.0.user.name', $owner->name)
            );

        $this->post(route('games.players.store', $game))
            ->assertRedirect(route('games.show', $game));

        $this->assertTrue($game->players()->whereBelongsTo($joiningUser)->where('seat', 2)->exists());
    }

    public function test_user_cannot_join_the_same_game_twice(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();

        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);

        $this->actingAs($user)
            ->post(route('games.players.store', $game))
            ->assertSessionHasErrors('game');

        $this->assertSame(1, $game->players()->count());
    }

    public function test_user_cannot_join_a_full_game(): void
    {
        $game = Game::factory()->create([
            'state' => new GameStateData(
                board: (new BoardStateFactory())->create(MapVariant::OneToThreePlayers),
            ),
        ]);

        foreach (range(1, 3) as $seat) {
            GamePlayer::factory()->create([
                'game_id' => $game->id,
                'seat' => $seat,
            ]);
        }

        $this->actingAs(User::factory()->create())
            ->post(route('games.players.store', $game))
            ->assertSessionHasErrors('game');

        $this->assertSame(3, $game->players()->count());
    }

    public function test_player_can_confirm_and_cancel_readiness(): void
    {
        $user = User::factory()->create();
        $gamePlayer = GamePlayer::factory()->create([
            'user_id' => $user->id,
            'is_ready' => false,
        ]);

        $this->actingAs($user)
            ->patch(route('games.players.readiness.update', [$gamePlayer->game, $gamePlayer]), [
                'is_ready' => true,
            ])
            ->assertRedirect(route('games.show', $gamePlayer->game));

        $this->assertTrue($gamePlayer->refresh()->is_ready);

        $this->patch(route('games.players.readiness.update', [$gamePlayer->game, $gamePlayer]), [
            'is_ready' => false,
        ])->assertRedirect(route('games.show', $gamePlayer->game));

        $this->assertFalse($gamePlayer->refresh()->is_ready);
    }

    public function test_player_cannot_change_another_players_readiness(): void
    {
        $gamePlayer = GamePlayer::factory()->create();

        $this->actingAs(User::factory()->create())
            ->patch(route('games.players.readiness.update', [$gamePlayer->game, $gamePlayer]), [
                'is_ready' => true,
            ])
            ->assertForbidden();

        $this->assertFalse($gamePlayer->refresh()->is_ready);
    }

    public function test_user_can_create_a_game_and_becomes_its_first_player(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('games.store'), [
                'map_variant' => MapVariant::OneToThreePlayers->value,
            ])
            ->assertRedirect(route('games.index'));

        $game = Game::query()->sole();

        $this->assertSame(MapVariant::OneToThreePlayers, $game->state->board->variant);
        $this->assertTrue($game->players()->whereBelongsTo($user)->where('seat', 1)->exists());
    }

    public function test_map_variant_is_required_and_must_be_valid(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('games.store'), ['map_variant' => 'unknown'])
            ->assertSessionHasErrors('map_variant');

        $this->assertSame(0, Game::query()->count());
    }

    public function test_owner_can_start_game_when_all_players_are_ready(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create(['random_seed' => 'repeatable-game-seed']);
        $ownerPlayer = GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        $secondPlayer = GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)
            ->post(route('games.start', $game))
            ->assertRedirect(route('games.show', $game));

        $game->refresh();

        $this->assertSame(GameStatus::Active, $game->status);
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertNotNull($game->started_at);
        $this->assertSame(1, $game->version);
        $this->assertNotNull($game->state->setupPool);
        $this->assertSame(2, $game->state->setupPool->playerCount);
        $this->assertSame($game->state->board->variant, $game->state->setupPool->mapVariant);
        $this->assertIsString($game->state->setupPool->roundScoringTiles[0]);
        $this->assertIsString($game->state->setupPool->bookActions[0]);
        $this->assertSame(
            $game->state->setupPool->roundScoringTiles[0],
            $game->state->round->scoringTileId,
        );
        $this->assertCount(7, $game->state->availableTownTileIds);
        $this->assertCount(4, $game->state->availablePalaceIds);
        $this->assertCount(6, $game->state->availableInventionIds);
        $this->assertCount(12, $game->state->availableCompetencyIds);
        $this->assertCount(10, $game->state->roundBonusIds);
        $this->assertEqualsCanonicalizing(
            [$ownerPlayer->id, $secondPlayer->id],
            $game->state->turnOrder,
        );
        $this->assertContains($game->active_player_id, [$owner->id, $secondUser->id]);

        $this->actingAs($owner)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.turnOrder', $game->state->turnOrder)
                    ->where('game.data.activePlayerId', $game->active_player_id)
                    ->where(
                        'game.data.availablePalaceIds',
                        $game->state->availablePalaceIds,
                    )
                    ->has('game.data.roundBonusOffers', 3)
                    ->where(
                        'game.data.roundBonusOffers.0.roundBonus',
                        $game->state->setupPool
                            ->availableRoundBonuses[0]
                            ->roundBonus
                            ->value,
                    )
                    ->where(
                        'game.data.roundBonusOffers.0.coins',
                        $game->state->setupPool
                            ->availableRoundBonuses[0]
                            ->coins,
                    ),
            );
    }

    public function test_only_owner_can_start_game(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($secondUser)
            ->post(route('games.start', $game))
            ->assertForbidden();

        $this->assertSame(GameStatus::Lobby, $game->refresh()->status);
        $this->assertNull($game->state->setupPool);
    }

    public function test_game_cannot_start_until_all_players_are_ready(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
            'is_ready' => false,
        ]);

        $this->actingAs($owner)
            ->post(route('games.start', $game))
            ->assertSessionHasErrors('game');

        $this->assertSame(GameStatus::Lobby, $game->refresh()->status);
    }

    public function test_game_requires_at_least_two_players_to_start(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);

        $this->actingAs($owner)
            ->post(route('games.start', $game))
            ->assertSessionHasErrors('game');

        $this->assertSame(GameStatus::Lobby, $game->refresh()->status);
    }

    public function test_active_player_can_choose_planning_bundle(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'planning-selection-seed']);

        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'seat' => $index + 1,
            ]);
        }

        $this->actingAs($users[0])->post(route('games.start', $game));
        $game->refresh();

        $activeUser = $users->firstWhere('id', $game->active_player_id);
        $bundle = collect($game->state->setupPool->planningBundles)->first(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland !== TerrainType::Wasteland
                && $bundle->faction !== Faction::Lizards,
        );

        $this->assertInstanceOf(User::class, $activeUser);
        $this->assertInstanceOf(PlanningBundleData::class, $bundle);

        $this->actingAs($activeUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $bundle->homeland->value,
            ])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $player = $game->players()->whereBelongsTo($activeUser)->sole();

        $this->assertSame($bundle->homeland, $player->homeland);
        $this->assertSame($bundle->faction, $player->faction);
        $this->assertNotNull($player->color);
        $this->assertCount(6, $game->state->setupPool->planningBundles);
        $this->assertCount(1, $game->state->planningSelections);
        $this->assertSame($player->id, $game->state->planningSelections[0]->playerId);
        $this->assertCount(1, $game->state->players);
        $this->assertSame($player->id, $game->state->players[0]->playerId);
        $this->assertSame($bundle->roundBonus, $game->state->players[0]->roundBonus);
        $this->assertSame(15, $game->state->players[0]->resources->coins);
        $this->assertSame(12, $game->state->players[0]->resources->power->bowlOne
            + $game->state->players[0]->resources->power->bowlTwo
            + $game->state->players[0]->resources->power->bowlThree);
        $this->assertNotSame($activeUser->id, $game->active_player_id);

        $this->get(route('games.show', $game))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.planningSelections.0.playerId', $player->id)
                    ->where(
                        'game.data.planningSelections.0.bundle.homeland',
                        $bundle->homeland->value,
                    )
                    ->where(
                        'game.data.planningSelections.0.bundle.faction',
                        $bundle->faction->value,
                    )
                    ->where(
                        'game.data.planningSelections.0.bundle.roundBonus',
                        $bundle->roundBonus->value,
                    )
                    ->where(
                        'game.data.players.'.($player->seat - 1).'.color',
                        $player->color->value,
                    )
                    ->has('game.data.playerBoardStates', 1)
                    ->where('game.data.playerBoardStates.0.playerId', $player->id)
                    ->where(
                        'game.data.playerBoardStates.0.shippingLevel',
                        $game->state->players[0]->shippingLevel,
                    )
                    ->where('game.data.playerBoardStates.0.terraformingLevel', 0)
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.banking',
                        $game->state->players[0]->knowledge->banking,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.law',
                        $game->state->players[0]->knowledge->law,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.engineering',
                        $game->state->players[0]->knowledge->engineering,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.medicine',
                        $game->state->players[0]->knowledge->medicine,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.power.bowlOne',
                        $game->state->players[0]->resources->power->bowlOne,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.power.bowlTwo',
                        $game->state->players[0]->resources->power->bowlTwo,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.power.bowlThree',
                        $game->state->players[0]->resources->power->bowlThree,
                    )
                    ->has('game.data.roundScoringTiles', 6)
                    ->where(
                        'game.data.finalRoundScoringTile',
                        $game->state->setupPool->additionalFinalRoundGoal->value,
                    )
                    ->has('game.data.bookActions', 3)
                    ->has('game.data.innovations', 6)
                    ->has('game.data.competencies', 12),
            );
    }

    public function test_inactive_player_cannot_choose_planning_bundle(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $otherUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)->post(route('games.start', $game));
        $game->refresh();

        $inactiveUser = $game->active_player_id === $owner->id ? $otherUser : $owner;
        $bundle = $game->state->setupPool->planningBundles[0];

        $this->actingAs($inactiveUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $bundle->homeland->value,
            ])
            ->assertForbidden();

        $this->assertCount(7, $game->refresh()->state->setupPool->planningBundles);
    }

    public function test_player_must_distribute_starting_resources_before_the_next_player_chooses(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'starting-resources-seed']);

        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'seat' => $index + 1,
            ]);
        }

        $this->actingAs($users[0])->post(route('games.start', $game));
        $game->refresh();

        $activeUser = $users->firstWhere('id', $game->active_player_id);
        $state = $game->state;
        $bundle = collect($state->setupPool->planningBundles)->first(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland === TerrainType::Wasteland,
        );

        $this->assertInstanceOf(User::class, $activeUser);
        $this->assertInstanceOf(PlanningBundleData::class, $bundle);

        $bundle->faction = Faction::Lizards;
        $game->update(['state' => $state]);

        $this->actingAs($activeUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => TerrainType::Wasteland->value,
            ])
            ->assertRedirect(route('games.show', $game));

        $game->refresh();
        $player = $game->players()->whereBelongsTo($activeUser)->sole();

        $this->assertSame($activeUser->id, $game->active_player_id);
        $this->assertSame(PendingInteractionType::ChooseStartingResources, $game->state->pendingInteraction?->type);
        $this->assertSame($player->id, $game->state->pendingInteraction?->playerId);
        $this->assertSame(1, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(2, $game->state->players[0]->knowledge->unassignedSteps);

        $this->post(route('games.starting-resources.store', $game))
            ->assertSessionHasErrors(['book_discipline', 'knowledge_disciplines']);

        $this->assertNotNull($game->refresh()->state->pendingInteraction);

        $this->post(route('games.starting-resources.store', $game), [
            'book_discipline' => 'banking',
            'knowledge_disciplines' => ['law', 'law'],
        ])->assertRedirect(route('games.show', $game));

        $game->refresh();

        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(0, $game->state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(2, $game->state->players[0]->knowledge->law);
        $this->assertNotSame($activeUser->id, $game->active_player_id);
    }
}
