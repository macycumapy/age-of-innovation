<?php

declare(strict_types=1);

namespace Feature\Game;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Board\Factories\BoardStateFactory;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GameLobbyTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_cannot_view_or_create_games(): void
    {
        $this->get(route('games.index'))->assertRedirect(route('login'));
        $this->post(route('games.store'))->assertRedirect(route('login'));
    }

    public function test_user_sees_open_lobbies_and_their_own_games(): void
    {
        $user = User::factory()->create();
        $openGame = Game::factory()->create([
            'state' => new GameStateData(
                board: (new BoardStateFactory())->create(MapVariant::OneToThreePlayers),
            ),
        ]);
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
                ->where('games.data.0.currentRound', null)
                ->where('games.data.0.mapVariant', MapVariant::ThreeToFivePlayers->value)
                ->where('games.data.0.playersCount', 1)
                ->where('games.data.0.isJoined', true)
                ->has('games.data.0.createdAt')
                ->missing('games.data.0.board')
                ->missing('games.data.0.players')
                ->missing('games.data.0.playerBoardStates')
                ->where('games.data.1.id', $openGame->id)
                ->where('games.data.1.currentRound', null)
                ->where('games.data.1.mapVariant', MapVariant::OneToThreePlayers->value)
                ->where('games.data.1.isJoined', false)
                ->missing('games.data.2')
            );
    }

    public function test_game_list_contains_the_current_round_for_an_active_game(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->active()->create(['round' => 3]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);

        $this->actingAs($user)
            ->get(route('games.index'))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->has('games.data', 1)
                    ->where('games.data.0.id', $game->id)
                    ->where('games.data.0.status', GameStatus::Active->value)
                    ->where('games.data.0.currentRound', 3)
                    ->missing('games.data.0.board'),
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
                ->where('game.data.players.0.name', $owner->name)
            );

        $this->post(route('games.players.store', $game))
            ->assertNoContent();

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
            ->assertNoContent();

        $this->assertTrue($gamePlayer->refresh()->is_ready);

        $this->patch(route('games.players.readiness.update', [$gamePlayer->game, $gamePlayer]), [
            'is_ready' => false,
        ])->assertNoContent();

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

    public function test_player_can_leave_a_lobby(): void
    {
        $owner = User::factory()->create();
        $leavingUser = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        $leavingPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $leavingUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($leavingUser)
            ->delete(route('games.players.destroy', [$game, $leavingPlayer]))
            ->assertNoContent();

        $this->assertModelMissing($leavingPlayer);
        $this->assertModelExists($game);
    }

    public function test_ownership_passes_to_the_next_player_when_owner_leaves(): void
    {
        $owner = User::factory()->create();
        $nextOwner = User::factory()->create();
        $game = Game::factory()->create();
        $ownerPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        $nextOwnerPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $nextOwner->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)
            ->delete(route('games.players.destroy', [$game, $ownerPlayer]))
            ->assertNoContent();

        $this->assertModelMissing($ownerPlayer);
        $this->assertSame(1, $nextOwnerPlayer->refresh()->seat);

        $this->actingAs($nextOwner)
            ->get(route('games.show', $game))
            ->assertInertia(fn (Assert $page) => $page->where('game.data.isOwner', true));
    }

    public function test_empty_lobby_is_deleted_when_its_owner_leaves(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        $ownerPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);

        $this->actingAs($owner)
            ->delete(route('games.players.destroy', [$game, $ownerPlayer]))
            ->assertNoContent();

        $this->assertModelMissing($ownerPlayer);
        $this->assertModelMissing($game);
    }

    public function test_owner_can_remove_another_player_from_a_lobby(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        $removedPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)
            ->delete(route('games.players.destroy', [$game, $removedPlayer]))
            ->assertNoContent();

        $this->assertModelMissing($removedPlayer);
    }

    public function test_non_owner_cannot_remove_another_player(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $otherUser->id,
            'seat' => 2,
        ]);
        $targetPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 3,
        ]);

        $this->actingAs($otherUser)
            ->delete(route('games.players.destroy', [$game, $targetPlayer]))
            ->assertForbidden();

        $this->assertModelExists($targetPlayer);
    }

    public function test_player_cannot_leave_an_active_game(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->active()->create();
        $gamePlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);

        $this->actingAs($user)
            ->delete(route('games.players.destroy', [$game, $gamePlayer]))
            ->assertForbidden();

        $this->assertModelExists($gamePlayer);
    }

    public function test_owner_cannot_remove_a_player_from_another_game(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        $otherGamePlayer = GamePlayer::factory()->create();

        $this->actingAs($owner)
            ->delete(route('games.players.destroy', [$game, $otherGamePlayer]))
            ->assertForbidden();

        $this->assertModelExists($otherGamePlayer);
    }

    public function test_user_can_create_a_game_and_becomes_its_first_player(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->post(route('games.store'), [
                'map_variant' => MapVariant::OneToThreePlayers->value,
            ]);

        $game = Game::query()->sole();

        $response->assertRedirect(route('games.show', $game));
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
}
