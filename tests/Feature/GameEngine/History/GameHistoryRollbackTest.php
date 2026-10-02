<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\History;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GameHistoryRollbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_undo_the_latest_action_and_remove_it_from_history(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create(['random_seed' => 'undo-game-seed']);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)->post(route('games.start', $game));

        $game->refresh();
        $this->assertSame(GameStatus::Active, $game->status);
        $this->assertSame(GameActionType::StartGame, $game->actions()->where('type', GameActionType::StartGame)->sole()->type);
        $this->assertSame(GameActionType::PhaseCheckpoint, $game->actions()->latest('sequence')->first()?->type);
        $activePlayerId = $game->active_player_id;
        $activeUser = User::query()->findOrFail($activePlayerId);
        $selectedBundle = $game->state->setupPool->planningBundles[0];

        $this->actingAs($activeUser)->post(route('games.planning-bundle.store', $game), [
            'homeland' => $selectedBundle->homeland->value,
        ]);

        $game->refresh();
        $this->assertSame(3, $game->actions()->count());

        $this->actingAs($secondUser)
            ->delete(route('games.history.latest.destroy', $game))
            ->assertForbidden();

        $this->actingAs($owner)
            ->delete(route('games.history.latest.destroy', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(GameStatus::Active, $game->status);
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertSame(1, $game->version);
        $this->assertSame($activePlayerId, $game->active_player_id);
        $this->assertCount(0, $game->state->planningSelections);
        $this->assertSame(2, $game->actions()->count());
        $this->assertTrue($game->players()->whereNull('faction')->whereNull('homeland')->exists());

        $this->delete(route('games.history.latest.destroy', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(GameStatus::Lobby, $game->status);
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertSame(0, $game->version);
        $this->assertNull($game->active_player_id);
        $this->assertNull($game->started_at);
        $this->assertNull($game->state->setupPool);
        $this->assertSame(0, $game->actions()->count());
    }

    public function test_owner_can_roll_back_to_a_phase_checkpoint_and_remove_later_history(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create(['random_seed' => 'phase-rollback-seed']);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)->post(route('games.start', $game));

        $game->refresh();
        $checkpoint = $game->actions()->where('type', GameActionType::PhaseCheckpoint)->sole();
        $this->assertSame($game->active_game_player_id, $checkpoint->payload['game']['active_game_player_id']);
        $activeUser = User::query()->findOrFail($game->active_player_id);
        $selectedBundle = $game->state->setupPool->planningBundles[0];

        $this->actingAs($activeUser)->post(route('games.planning-bundle.store', $game), [
            'homeland' => $selectedBundle->homeland->value,
        ]);

        $this->actingAs($owner)
            ->delete(route('games.history.destroy', [$game, $checkpoint]))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(GameStatus::Active, $game->status);
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertSame(1, $game->version);
        $this->assertCount(0, $game->state->planningSelections);
        $this->assertSame($checkpoint->sequence, $game->actions()->max('sequence'));
        $this->assertModelExists($checkpoint);
    }

    public function test_only_owner_can_roll_back_to_a_phase_checkpoint(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create(['random_seed' => 'forbidden-phase-rollback-seed']);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)->post(route('games.start', $game));

        $checkpoint = $game->actions()->where('type', GameActionType::PhaseCheckpoint)->sole();

        $this->actingAs($secondUser)
            ->delete(route('games.history.destroy', [$game, $checkpoint]))
            ->assertForbidden();

        $this->assertSame(2, $game->actions()->count());
    }

    public function test_history_rollback_rejects_an_action_that_is_not_a_phase_checkpoint(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        $action = GameAction::factory()->create([
            'game_id' => $game->id,
            'player_id' => $owner->id,
            'sequence' => 1,
        ]);

        $this->actingAs($owner)
            ->delete(route('games.history.destroy', [$game, $action]))
            ->assertSessionHasErrors('history');

        $this->assertModelExists($action);
    }

    public function test_owner_cannot_undo_the_latest_action_outside_development(): void
    {
        $this->withoutMiddleware(\Illuminate\Foundation\Http\Middleware\PreventRequestForgery::class);
        $this->app->detectEnvironment(static fn (): string => 'production');

        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);

        $this->actingAs($owner)
            ->delete(route('games.history.latest.destroy', $game))
            ->assertForbidden();
    }
}
