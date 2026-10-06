<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\History;

use App\Broadcasting\GameChannel;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Events\GameHistoryChanged;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

final class GameHistoryBroadcastTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_player_can_subscribe_to_game_updates(): void
    {
        $user = User::factory()->create();
        $joinedGame = Game::factory()->active()->create();
        GamePlayer::factory()->for($joinedGame)->for($user)->create();

        $channel = app(GameChannel::class);

        $this->assertTrue($channel->join($user, $joinedGame->id));
    }

    public function test_spectator_can_subscribe_to_active_game_updates(): void
    {
        $spectator = User::factory()->create();
        $game = Game::factory()->active()->create();
        GamePlayer::factory()->for($game)->create();

        $this->assertFalse($game->players()->where('user_id', $spectator->id)->exists());
        $this->assertTrue(app(GameChannel::class)->join($spectator, $game->id));
    }

    public function test_nonexistent_game_cannot_be_subscribed_to(): void
    {
        $user = User::factory()->create();

        $this->assertFalse(app(GameChannel::class)->join($user, 0));
    }

    public function test_appending_history_dispatches_realtime_update(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create(['version' => 1]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
        ]);
        Event::fake([GameHistoryChanged::class]);

        app(AppendGameHistoryAction::class)->execute(
            lockedGame: $game,
            player: $player,
            type: GameActionType::Pass,
            payload: [],
            events: [],
            stateVersionBefore: 0,
            stateVersionAfter: 1,
        );

        Event::assertDispatched(
            GameHistoryChanged::class,
            fn (GameHistoryChanged $event): bool => $event->gameId === $game->id,
        );
    }
}
