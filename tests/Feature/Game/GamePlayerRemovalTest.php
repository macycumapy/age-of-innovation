<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Events\GameChanged;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

final class GamePlayerRemovalTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_leaving_an_empty_lobby_notifies_open_pages_that_the_game_was_deleted(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();
        $player = GamePlayer::factory()->for($game)->for($user)->create(['seat' => 1]);
        Event::fake([GameChanged::class]);

        $this->actingAs($user)
            ->deleteJson(route('games.players.destroy', [$game, $player]))
            ->assertNoContent();

        $this->assertModelMissing($game);
        $this->assertModelMissing($player);
        Event::assertDispatched(
            GameChanged::class,
            fn (GameChanged $event): bool => $event->gameId === $game->id && $event->deleted,
        );
        $this->get(route('games.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('games.data', 0));
    }

    public function test_leaving_a_populated_lobby_keeps_other_players_on_the_game_page(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();
        $player = GamePlayer::factory()->for($game)->for($user)->create(['seat' => 1]);
        $remainingUser = User::factory()->create();
        GamePlayer::factory()->for($game)->for($remainingUser)->create(['seat' => 2]);
        Event::fake([GameChanged::class]);

        $this->actingAs($user)
            ->deleteJson(route('games.players.destroy', [$game, $player]))
            ->assertNoContent();

        Event::assertDispatched(
            GameChanged::class,
            fn (GameChanged $event): bool => $event->gameId === $game->id && ! $event->deleted,
        );
        $this->actingAs($remainingUser)
            ->get(route('games.show', $game))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('game.data.playersCount', 1)
                ->where('game.data.isOwner', true));
    }
}
