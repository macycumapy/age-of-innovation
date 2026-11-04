<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\History;

use App\Models\Game;
use App\Models\GameAction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class GameHistoryQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_partial_game_reload_refreshes_history_without_unrelated_page_props(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();

        $response = $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->component('games/Show')
                    ->has('game.data.history.data', 0)
                    ->has('settings'),
            );

        $action = GameAction::factory()->create([
            'game_id' => $game->id,
            'player_id' => $user->id,
            'sequence' => 1,
            'state_version_before' => 0,
            'state_version_after' => 1,
        ]);

        $this->get(route('games.show', $game), [
            'X-Inertia' => 'true',
            'X-Inertia-Version' => $response->viewData('page')['version'],
            'X-Inertia-Partial-Component' => 'games/Show',
            'X-Inertia-Partial-Data' => 'game',
        ])
            ->assertOk()
            ->assertHeader('X-Inertia', 'true')
            ->assertJsonPath('component', 'games/Show')
            ->assertJsonPath('props.game.data.id', $game->id)
            ->assertJsonCount(1, 'props.game.data.history.data')
            ->assertJsonPath('props.game.data.history.data.0.id', $action->id)
            ->assertJsonPath('props.game.data.history.hasMore', false)
            ->assertJsonMissingPath('props.settings');
    }

    public function test_game_history_is_loaded_in_batches_of_twenty_five(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();

        foreach (range(1, 30) as $sequence) {
            GameAction::factory()->create([
                'game_id' => $game->id,
                'player_id' => $user->id,
                'sequence' => $sequence,
                'state_version_before' => $sequence - 1,
                'state_version_after' => $sequence,
            ]);
        }

        $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->has('game.data.history.data', 25)
                    ->where('game.data.history.hasMore', true)
                    ->where('game.data.history.data.0.sequence', 30)
                    ->where('game.data.history.data.24.sequence', 6),
            );

        $this->getJson(route('games.history', ['game' => $game, 'before_sequence' => 6]))
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('data.0.sequence', 5)
            ->assertJsonPath('data.4.sequence', 1)
            ->assertJsonPath('hasMore', false);
    }
}
