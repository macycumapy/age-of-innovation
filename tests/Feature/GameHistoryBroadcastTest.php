<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Broadcasting\GameChannel;
use App\Domain\Game\Actions\AppendGameHistoryAction;
use App\Domain\Game\Enums\GameActionType;
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

    public function test_visible_game_can_be_subscribed_to_and_hidden_game_cannot(): void
    {
        $user = User::factory()->create();
        $joinedGame = Game::factory()->active()->create();
        $hiddenGame = Game::factory()->active()->create();
        GamePlayer::factory()->for($joinedGame)->for($user)->create();

        $channel = app(GameChannel::class);

        $this->assertTrue($channel->join($user, $joinedGame->id));
        $this->assertFalse($channel->join($user, $hiddenGame->id));
    }

    public function test_appending_history_dispatches_realtime_update(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create(['version' => 1]);
        Event::fake([GameHistoryChanged::class]);

        app(AppendGameHistoryAction::class)->execute(
            lockedGame: $game,
            user: $user,
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
