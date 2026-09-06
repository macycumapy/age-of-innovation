<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Actions\JoinGameAction;
use App\Domain\Game\Actions\SetGamePlayerReadinessAction;
use App\Events\GamePlayersChanged;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class GamePlayersBroadcastTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_joining_a_game_dispatches_players_changed_event(): void
    {
        $game = Game::factory()->create();
        $user = User::factory()->create();
        Event::fake([GamePlayersChanged::class]);

        app(JoinGameAction::class)->execute($game, $user);

        Event::assertDispatched(
            GamePlayersChanged::class,
            fn (GamePlayersChanged $event): bool => $event->gameId === $game->id,
        );
    }

    public function test_rejected_join_does_not_dispatch_players_changed_event(): void
    {
        $gamePlayer = GamePlayer::factory()->create();
        Event::fake([GamePlayersChanged::class]);

        $this->actingAs($gamePlayer->user)
            ->post(route('games.players.store', $gamePlayer->game))
            ->assertSessionHasErrors('game');

        Event::assertNotDispatched(GamePlayersChanged::class);
    }

    public function test_changing_readiness_dispatches_players_changed_event(): void
    {
        $gamePlayer = GamePlayer::factory()->create(['is_ready' => false]);
        Event::fake([GamePlayersChanged::class]);

        app(SetGamePlayerReadinessAction::class)->execute($gamePlayer, true);

        Event::assertDispatched(
            GamePlayersChanged::class,
            fn (GamePlayersChanged $event): bool => $event->gameId === $gamePlayer->game_id,
        );
    }

    public function test_unchanged_readiness_does_not_dispatch_players_changed_event(): void
    {
        $gamePlayer = GamePlayer::factory()->create(['is_ready' => false]);
        Event::fake([GamePlayersChanged::class]);

        app(SetGamePlayerReadinessAction::class)->execute($gamePlayer, false);

        Event::assertNotDispatched(GamePlayersChanged::class);
    }
}
