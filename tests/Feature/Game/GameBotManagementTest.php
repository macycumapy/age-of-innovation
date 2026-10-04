<?php

declare(strict_types=1);

namespace Feature\Game;

use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Domain\Settings\Enums\SettingKey;
use App\Jobs\PlayAutomatedTurnJob;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\SettingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GameBotManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingSeeder::class);

        Setting::query()->where('key', SettingKey::BotsEnabled->value)->firstOrFail()->update(['value' => true]);
        Setting::query()->where('key', SettingKey::BotDifficulties->value)->firstOrFail()->update(['value' => ['fast', 'balanced', 'strong']]);
    }

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

    public function test_game_dispatches_automated_turn_by_game_player_id(): void
    {
        Queue::fake();

        $owner = User::factory()->create();
        $game = Game::factory()->create(['status' => GameStatus::Lobby]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);

        $this->actingAs($owner)->post(route('games.bots.store', $game), [
            'difficulty' => GameBotDifficulty::Fast->value,
        ])->assertNoContent();

        $botPlayer = $game->players()->whereNull('user_id')->firstOrFail();
        $game->update([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_game_player_id' => $botPlayer->id,
        ]);

        Queue::assertPushed(
            PlayAutomatedTurnJob::class,
            fn (PlayAutomatedTurnJob $job): bool => $job->gameId === $game->id
                && $job->gamePlayerId === $botPlayer->id
                && $job->queue === PlayAutomatedTurnJob::QUEUE,
        );
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

    public function test_disabled_bots_cannot_be_added_and_lobby_receives_settings(): void
    {
        Setting::query()->where('key', SettingKey::BotsEnabled->value)->firstOrFail()->update(['value' => false]);
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $owner->id, 'seat' => 1]);

        $this->actingAs($owner)->post(route('games.bots.store', $game), ['difficulty' => 'fast'])
            ->assertSessionHasErrors('game');
        $this->get(route('games.show', $game))->assertInertia(fn (Assert $page) => $page
            ->where('settings.bots.enabled', false));

        $this->assertSame(1, $game->players()->count());
    }

    public function test_unavailable_difficulty_cannot_be_added_and_lobby_receives_allowed_difficulties(): void
    {
        Setting::query()->where('key', SettingKey::BotDifficulties->value)->firstOrFail()->update(['value' => ['fast', 'balanced']]);
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $owner->id, 'seat' => 1]);

        $this->actingAs($owner)->post(route('games.bots.store', $game), ['difficulty' => 'strong'])
            ->assertSessionHasErrors('difficulty');
        $this->get(route('games.show', $game))->assertInertia(fn (Assert $page) => $page
            ->where('settings.bots.enabled', true)
            ->where('settings.bots.available_difficulties', ['fast', 'balanced']));

        $this->assertSame(1, $game->players()->count());
        $this->post(route('games.bots.store', $game), ['difficulty' => 'balanced'])->assertNoContent();
    }
}
