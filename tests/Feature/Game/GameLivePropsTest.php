<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GameLivePropsTest extends TestCase
{
    use LazilyRefreshDatabase;

    #[DataProvider('gameStates')]
    public function test_lobby_and_active_game_declare_their_private_live_prop(bool $active): void
    {
        $user = User::factory()->create();
        $game = $active ? Game::factory()->active()->create() : Game::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('games/Show')
                ->where('game.data.id', $game->id)
                ->has('settings'));

        $this->assertSame([
            'game' => [
                'listeners' => [[
                    'channel' => ['name' => "games.{$game->id}", 'type' => 'private'],
                    'events' => ['game.changed'],
                ]],
                'throttle' => 100,
            ],
        ], $response->viewData('page')['liveProps']);
    }

    /** @return array<string, array{bool}> */
    public static function gameStates(): array
    {
        return ['lobby' => [false], 'active game' => [true]];
    }

    public function test_live_reload_updates_lobby_players_and_readiness_without_other_page_props(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        $ownerPlayer = GamePlayer::factory()->for($game)->for($owner)->create(['seat' => 1]);
        $response = $this->actingAs($owner)->get(route('games.show', $game))->assertOk();

        $ownerPlayer->update(['is_ready' => true]);
        GamePlayer::factory()->ready()->for($game)->create(['seat' => 2]);

        $this->get(route('games.show', $game), $this->partialHeaders('game', $response->viewData('page')['version']))
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'games/Show')
            ->assertJsonPath('props.game.data.playersCount', 2)
            ->assertJsonPath('props.game.data.players.0.isReady', true)
            ->assertJsonPath('props.game.data.players.1.isReady', true)
            ->assertJsonPath('props.game.data.canStart', true)
            ->assertJsonMissingPath('props.settings')
            ->assertJsonMissingPath('props.auth')
            ->assertJsonPath('liveProps.game.listeners.0.events', ['game.changed']);
    }

    public function test_live_game_data_is_resolved_for_the_requesting_user(): void
    {
        $owner = User::factory()->create();
        $spectator = User::factory()->create();
        $game = Game::factory()->active()->create();
        GamePlayer::factory()->for($game)->for($owner)->create(['seat' => 1]);
        $response = $this->actingAs($owner)->get(route('games.show', $game))->assertOk();
        $headers = $this->partialHeaders('game', $response->viewData('page')['version']);

        $this->get(route('games.show', $game), $headers)
            ->assertOk()
            ->assertJsonPath('props.game.data.isOwner', true)
            ->assertJsonPath('props.game.data.isJoined', true);

        $this->actingAs($spectator)->get(route('games.show', $game), $headers)
            ->assertOk()
            ->assertJsonPath('props.game.data.isOwner', false)
            ->assertJsonPath('props.game.data.isJoined', false);
    }

    public function test_unrelated_partial_reload_does_not_load_players_or_history(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();
        $response = $this->actingAs($user)->get(route('games.show', $game))->assertOk();
        DB::enableQueryLog();
        DB::flushQueryLog();

        $reload = $this->get(
            route('games.show', $game),
            $this->partialHeaders('settings', $response->viewData('page')['version']),
        );
        $queries = DB::getQueryLog();
        DB::disableQueryLog();

        $reload->assertOk()
            ->assertJsonStructure(['props' => ['settings']])
            ->assertJsonMissingPath('props.game');

        $this->assertEmpty(array_filter(
            $queries,
            fn (array $query): bool => str_contains($query['query'], 'game_players')
                || str_contains($query['query'], 'game_actions'),
        ));
    }

    /** @return array<string, string> */
    private function partialHeaders(string $prop, ?string $version): array
    {
        return [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $version ?? '',
            'X-Inertia-Partial-Component' => 'games/Show',
            'X-Inertia-Partial-Data' => $prop,
        ];
    }
}
