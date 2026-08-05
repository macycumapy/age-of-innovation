<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class GameModelsTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_game_stores_state_and_resolves_its_relationships(): void
    {
        $activePlayer = User::factory()->create();
        $game = Game::factory()->create([
            'active_player_id' => $activePlayer->id,
            'state' => ['schemaVersion' => 1, 'round' => ['number' => 1]],
        ]);
        $gamePlayer = GamePlayer::factory()->recycle($game)->recycle($activePlayer)->create([
            'seat' => 1,
        ]);
        $action = GameAction::factory()->recycle($game)->create([
            'player_id' => $activePlayer->id,
            'sequence' => 1,
        ]);

        $this->assertSame(1, $game->state['schemaVersion']);
        $this->assertTrue($game->activePlayer->is($activePlayer));
        $this->assertTrue($game->players->contains($gamePlayer));
        $this->assertTrue($game->actions->contains($action));
        $this->assertTrue($gamePlayer->user->is($activePlayer));
        $this->assertTrue($action->player->is($activePlayer));
    }

    public function test_game_action_casts_payload_and_events_to_arrays(): void
    {
        $action = GameAction::factory()->create([
            'payload' => ['hexId' => 'D7'],
            'events' => [['type' => 'building_built']],
        ]);

        $this->assertSame(['hexId' => 'D7'], $action->payload);
        $this->assertSame([['type' => 'building_built']], $action->events);
    }

    public function test_a_user_cannot_join_the_same_game_twice(): void
    {
        $game = Game::factory()->create();
        $user = User::factory()->create();

        GamePlayer::factory()->recycle($game)->recycle($user)->create(['seat' => 1]);

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);

        GamePlayer::factory()->recycle($game)->recycle($user)->create(['seat' => 2]);
    }
}
