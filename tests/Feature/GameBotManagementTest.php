<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Enums\GameStatus;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameBotManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_add_a_ready_bot_with_selected_difficulty(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['status' => GameStatus::Lobby]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);

        $this->actingAs($owner)
            ->post(route('games.bots.store', $game), [
                'difficulty' => GameBotDifficulty::Strong->value,
            ])
            ->assertNoContent();

        $botPlayer = $game->players()->where('seat', 2)->firstOrFail();
        $this->assertTrue($botPlayer->is_ready);
        $this->assertSame(GameBotDifficulty::Strong, $botPlayer->bot_difficulty);
        $this->assertNull($botPlayer->user_id);
        $this->assertSame(1, User::query()->count());
    }

    public function test_non_owner_cannot_add_a_bot(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $game = Game::factory()->create(['status' => GameStatus::Lobby]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $member->id,
            'seat' => 2,
        ]);

        $this->actingAs($member)
            ->post(route('games.bots.store', $game), [
                'difficulty' => GameBotDifficulty::Balanced->value,
            ])
            ->assertForbidden();

        $this->assertSame(2, $game->players()->count());
    }

    public function test_bot_difficulty_must_be_supported(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create(['status' => GameStatus::Lobby]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);

        $this->actingAs($owner)
            ->post(route('games.bots.store', $game), ['difficulty' => 'impossible'])
            ->assertSessionHasErrors('difficulty');

        $this->assertSame(1, $game->players()->count());
    }
}
