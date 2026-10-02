<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Models\Builders\GameBuilder;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameStateLifecycleTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_uses_custom_builder(): void
    {
        $this->assertInstanceOf(GameBuilder::class, Game::query());
    }

    public function test_game_tracks_when_the_active_players_turn_started(): void
    {
        $firstPlayer = User::factory()->create();
        $secondPlayer = User::factory()->create();
        $game = Game::factory()->create();

        $this->freezeTime(function () use ($game, $firstPlayer, $secondPlayer): void {
            $game->update(['active_player_id' => $firstPlayer->id]);

            $this->assertNotNull($game->current_turn_started_at);
            $firstTurnStartedAt = $game->current_turn_started_at->getTimestamp();
            $this->assertSame(now()->getTimestamp(), $firstTurnStartedAt);

            $game->update(['version' => 1]);

            $this->assertSame($firstTurnStartedAt, $game->current_turn_started_at?->getTimestamp());

            $this->travel(10)->seconds();
            $game->update(['active_player_id' => $secondPlayer->id]);

            $this->assertSame(now()->getTimestamp(), $game->current_turn_started_at?->getTimestamp());
            $this->assertNotSame($firstTurnStartedAt, $game->current_turn_started_at?->getTimestamp());
        });
    }
}
