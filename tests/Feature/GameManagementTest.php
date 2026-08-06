<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Enums\MapVariant;
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
}
