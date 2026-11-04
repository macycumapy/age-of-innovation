<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Domain\Game\Enums\GameStatus;
use App\Events\GameListChanged;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

final class GameListLivePropsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_game_list_declares_a_private_live_prop_without_broadcasting_user_data(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('games.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('games/Index')->has('games.data', 0));

        $this->assertSame([
            'games' => [
                'listeners' => [[
                    'channel' => ['name' => 'games', 'type' => 'private'],
                    'events' => ['games.changed'],
                ]],
            ],
        ], $response->viewData('page')['liveProps']);

        $event = new GameListChanged();
        $this->assertSame('private-games', $event->broadcastOn()->name);
        $this->assertSame([], $event->broadcastWith());
    }

    public function test_partial_reload_updates_game_cards_and_preserves_user_visibility(): void
    {
        $user = User::factory()->create();
        $ownGame = Game::factory()->active()->create();
        $player = GamePlayer::factory()->for($ownGame)->for($user)->create(['seat' => 1]);
        $closingLobby = Game::factory()->create();
        $deletedLobby = Game::factory()->create();
        Game::factory()->active()->create();

        $response = $this->actingAs($user)
            ->get(route('games.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('games.data', 3));

        $ownGame->update(['round' => 4, 'status' => GameStatus::Finished]);
        GamePlayer::factory()->for($ownGame)->create(['seat' => 2]);
        $closingLobby->update(['status' => GameStatus::Active]);
        $deletedLobby->delete();
        $newLobby = Game::factory()->create();

        $this->get(route('games.index'), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $response->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'games/Index',
            'X-Inertia-Partial-Data' => 'games',
        ])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'games/Index')
            ->assertJsonCount(2, 'props.games.data')
            ->assertJsonPath('props.games.data.0.id', $newLobby->id)
            ->assertJsonPath('props.games.data.0.status', GameStatus::Lobby->value)
            ->assertJsonPath('props.games.data.0.isJoined', false)
            ->assertJsonPath('props.games.data.1.id', $ownGame->id)
            ->assertJsonPath('props.games.data.1.status', GameStatus::Finished->value)
            ->assertJsonPath('props.games.data.1.currentRound', 4)
            ->assertJsonPath('props.games.data.1.playersCount', 2)
            ->assertJsonPath('props.games.data.1.isJoined', true)
            ->assertJsonMissingPath('props.auth')
            ->assertJsonMissingPath('props.games.data.1.board');

        $this->actingAs(User::factory()->create())
            ->get(route('games.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('games.data', 1)
                ->where('games.data.0.id', $newLobby->id));

        $player->delete();

        $this->actingAs($user)
            ->get(route('games.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('games.data', 1)
                ->where('games.data.0.id', $newLobby->id));
    }

    public function test_authenticated_users_can_subscribe_to_game_list_updates(): void
    {
        $this->configureBroadcaster();

        $this->actingAs(User::factory()->create())
            ->postJson('/broadcasting/auth', ['channel_name' => 'private-games', 'socket_id' => '123.456'])
            ->assertOk()
            ->assertJsonStructure(['auth']);
    }

    public function test_guests_cannot_subscribe_to_game_list_updates(): void
    {
        $this->configureBroadcaster();

        $this->postJson('/broadcasting/auth', ['channel_name' => 'private-games', 'socket_id' => '123.456'])
            ->assertForbidden();
    }

    public function test_creating_and_deleting_a_game_dispatches_list_updates(): void
    {
        Event::fake([GameListChanged::class]);

        $game = Game::factory()->create();
        Event::assertDispatchedTimes(GameListChanged::class, 1);

        $game->delete();
        Event::assertDispatchedTimes(GameListChanged::class, 2);
    }

    #[DataProvider('summaryChanges')]
    public function test_changing_game_summary_dispatches_a_list_update(string $attribute, GameStatus|int $value): void
    {
        $game = Game::factory()->create();
        Event::fake([GameListChanged::class]);

        $game->update([$attribute => $value]);

        Event::assertDispatchedTimes(GameListChanged::class, 1);
    }

    /** @return array<string, array{string, GameStatus|int}> */
    public static function summaryChanges(): array
    {
        return [
            'started' => ['status', GameStatus::Active],
            'finished' => ['status', GameStatus::Finished],
            'abandoned' => ['status', GameStatus::Abandoned],
            'next round' => ['round', 2],
        ];
    }

    public function test_changing_game_state_without_changing_its_summary_does_not_refresh_the_list(): void
    {
        $game = Game::factory()->create();
        Event::fake([GameListChanged::class]);

        $game->increment('version');

        Event::assertNotDispatched(GameListChanged::class);
    }

    public function test_adding_and_removing_a_player_dispatches_list_updates(): void
    {
        $game = Game::factory()->create();
        Event::fake([GameListChanged::class]);

        $player = GamePlayer::factory()->for($game)->create();
        Event::assertDispatchedTimes(GameListChanged::class, 1);

        $player->delete();
        Event::assertDispatchedTimes(GameListChanged::class, 2);
    }

    public function test_changing_player_membership_dispatches_a_list_update(): void
    {
        $player = GamePlayer::factory()->create();
        $user = User::factory()->create();
        Event::fake([GameListChanged::class]);

        $player->update(['user_id' => $user->id]);

        Event::assertDispatchedTimes(GameListChanged::class, 1);
    }

    public function test_changing_readiness_does_not_refresh_the_list(): void
    {
        $player = GamePlayer::factory()->create(['is_ready' => false]);
        Event::fake([GameListChanged::class]);

        $player->update(['is_ready' => true]);

        Event::assertNotDispatched(GameListChanged::class);
    }

    public function test_list_updates_are_dispatched_after_commit(): void
    {
        Event::fake([GameListChanged::class]);

        DB::transaction(function (): void {
            Game::factory()->create();

            Event::assertNotDispatched(GameListChanged::class);
        });

        Event::assertDispatchedTimes(GameListChanged::class, 1);
    }

    public function test_rolled_back_changes_do_not_dispatch_list_updates(): void
    {
        Event::fake([GameListChanged::class]);

        DB::beginTransaction();

        try {
            Game::factory()->create();

            Event::assertNotDispatched(GameListChanged::class);
        } finally {
            DB::rollBack();
        }

        Event::assertNotDispatched(GameListChanged::class);
    }

    private function configureBroadcaster(): void
    {
        config([
            'broadcasting.default' => 'reverb',
            'broadcasting.connections.reverb.key' => 'test-key',
            'broadcasting.connections.reverb.secret' => 'test-secret',
            'broadcasting.connections.reverb.app_id' => 'test-app',
        ]);

        require base_path('routes/channels.php');
    }
}
