<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Events\GameChanged;
use App\Events\GamePlayersChanged;
use App\Mcp\Servers\GameServer;
use App\Mcp\Tools\GameSessionTool;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Laravel\Mcp\Server\Contracts\Transport;
use Laravel\Mcp\Server\Registrar;
use Tests\TestCase;

class GameServerTest extends TestCase
{
    use RefreshDatabase;

    public function test_server_context_contains_the_game_instructions(): void
    {
        $server = new GameServer($this->createMock(Transport::class));
        $instructions = $server->createContext()->instructions;

        $this->assertStringContainsString('Use join_game with game_id', $instructions);
        $this->assertStringContainsString('GAME_MCP_USER_ID', $instructions);
        $this->assertStringContainsString('Stage an action, then explicitly confirm or roll back.', $instructions);
        $this->assertStringContainsString('Use the current version for action mutations.', $instructions);
        $this->assertStringContainsString('specify game_id on each call.', $instructions);
    }

    public function test_server_is_registered_and_requires_a_configured_user(): void
    {
        $this->assertNotNull(app(Registrar::class)->getLocalServer('game'));
        config(['game-mcp.user_id' => null]);
        GameServer::tool(GameSessionTool::class, ['operation' => 'get_state'])
            ->assertHasErrors()->assertSee('GAME_MCP_USER_ID');
    }

    public function test_selection_and_rollback_preserve_the_complete_game_and_history(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        $before = $game->fresh()->getAttributes();
        Event::fake([GameChanged::class]);

        GameServer::tool(GameSessionTool::class, ['operation' => 'get_state'])->assertOk()->assertDontSee('strategicPlan');
        GameServer::tool(GameSessionTool::class, ['operation' => 'get_legal_actions'])->assertOk()->assertSee($actionId);
        GameServer::tool(GameSessionTool::class, ['operation' => 'stage_action', 'version' => $game->version, 'action_id' => $actionId])->assertOk();
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertSame(0, $game->actions()->count());

        GameServer::tool(GameSessionTool::class, ['operation' => 'rollback_action', 'version' => $game->version])->assertOk();
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertNull(Cache::get('game-mcp:staged:'.$player->id));
        GameServer::tool(GameSessionTool::class, ['operation' => 'confirm_action', 'version' => $game->version])->assertHasErrors();
        $this->assertSame(0, $game->actions()->count());
        Event::assertNotDispatched(GameChanged::class);
    }

    public function test_configured_user_can_join_a_lobby_and_read_its_state(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->create(['game_id' => $game->id, 'seat' => 1]);
        Event::fake([GamePlayersChanged::class, GameChanged::class]);
        config(['game-mcp.user_id' => $user->id]);

        GameServer::tool(GameSessionTool::class, ['operation' => 'join_game', 'game_id' => $game->id])
            ->assertOk()->assertSee('"joined":true')->assertSee('"user_id":'.$user->id);

        $player = $user->gamePlayers()->sole();
        $this->assertSame($game->id, $player->game_id);
        $this->assertSame(2, $player->seat);
        $this->assertNull($player->bot_difficulty);
        $this->assertFalse($player->is_ready);
        $this->assertSame(0, $game->actions()->count());
        Event::assertDispatched(GamePlayersChanged::class);
        Event::assertDispatched(GameChanged::class, fn (GameChanged $event): bool => $event->gameId === $game->id);
        Event::assertDispatchedTimes(GameChanged::class, 1);
        GameServer::tool(GameSessionTool::class, ['operation' => 'get_state', 'game_id' => $game->id])->assertOk();
    }

    public function test_join_game_requires_a_game_and_valid_configured_user(): void
    {
        $game = Game::factory()->create();
        config(['game-mcp.user_id' => null]);
        GameServer::tool(GameSessionTool::class, ['operation' => 'join_game', 'game_id' => $game->id])->assertHasErrors();

        config(['game-mcp.user_id' => User::factory()->create()->id]);
        GameServer::tool(GameSessionTool::class, ['operation' => 'join_game'])->assertHasErrors();
        GameServer::tool(GameSessionTool::class, ['operation' => 'join_game', 'game_id' => $game->id + 1000])->assertHasErrors();
        $this->assertSame(0, $game->players()->count());
    }

    public function test_join_game_rejects_duplicate_membership(): void
    {
        $user = User::factory()->create();
        $player = GamePlayer::factory()->create(['user_id' => $user->id]);
        config(['game-mcp.user_id' => $user->id]);

        GameServer::tool(GameSessionTool::class, ['operation' => 'join_game', 'game_id' => $player->game_id])->assertHasErrors();
        $this->assertSame(1, $user->gamePlayers()->count());
    }

    public function test_join_game_rejects_full_lobbies_and_started_games(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();
        config(['game-mcp.user_id' => $user->id]);
        for ($seat = 1; $seat <= $game->state->board->variant->maxPlayers(); $seat++) {
            GamePlayer::factory()->create(['game_id' => $game->id, 'seat' => $seat]);
        }

        GameServer::tool(GameSessionTool::class, ['operation' => 'join_game', 'game_id' => $game->id])->assertHasErrors();
        foreach ([GameStatus::Active, GameStatus::Finished, GameStatus::Abandoned] as $status) {
            $closedGame = Game::factory()->create(['status' => $status]);
            GameServer::tool(GameSessionTool::class, ['operation' => 'join_game', 'game_id' => $closedGame->id])->assertHasErrors();
            $this->assertSame(0, $closedGame->players()->count());
        }
        $this->assertSame(0, $user->gamePlayers()->count());
    }

    public function test_user_id_is_not_interpreted_as_a_player_id(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        $user = User::factory()->create(['id' => $player->id + 1000]);
        $player->update(['user_id' => $user->id]);
        config(['game-mcp.user_id' => $user->id]);

        GameServer::tool(GameSessionTool::class, ['operation' => 'get_state'])->assertOk()->assertSee('"user_id":'.$user->id);
        GameServer::tool(GameSessionTool::class, ['operation' => 'get_legal_actions'])->assertOk()->assertSee($actionId);
    }

    public function test_user_without_a_player_cannot_access_a_player_with_the_same_id(): void
    {
        $user = User::factory()->create();
        GamePlayer::factory()->create(['id' => $user->id]);
        config(['game-mcp.user_id' => $user->id]);

        GameServer::tool(GameSessionTool::class, ['operation' => 'get_state'])
            ->assertHasErrors()->assertSee('has not joined any game yet');
    }

    public function test_multiple_games_require_an_explicit_game_id(): void
    {
        [$game, $player] = $this->createGameSession();
        GamePlayer::factory()->create(['user_id' => $player->user_id]);

        GameServer::tool(GameSessionTool::class, ['operation' => 'get_state'])
            ->assertHasErrors()->assertSee('Specify game_id');
        GameServer::tool(GameSessionTool::class, ['operation' => 'get_state', 'game_id' => $game->id])
            ->assertOk()->assertSee('"game_id":'.$game->id);
    }

    public function test_explicit_game_id_cannot_access_another_users_game(): void
    {
        $this->createGameSession();
        $otherGame = Game::factory()->create();
        GamePlayer::factory()->create(['game_id' => $otherGame->id]);

        GameServer::tool(GameSessionTool::class, ['operation' => 'get_state', 'game_id' => $otherGame->id])
            ->assertHasErrors()->assertSee('does not participate');
    }

    public function test_confirmation_executes_once_and_records_only_the_game_action(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        Event::fake([GameChanged::class]);
        GameServer::tool(GameSessionTool::class, ['operation' => 'stage_action', 'version' => $game->version, 'action_id' => $actionId])->assertOk();
        GameServer::tool(GameSessionTool::class, ['operation' => 'confirm_action', 'version' => $game->version])->assertOk();
        $this->assertGreaterThan($game->version, $game->fresh()->version);
        $this->assertNotNull($player->fresh()->homeland);
        $this->assertSame(1, $game->actions()->count());
        Event::assertDispatched(GameChanged::class, fn (GameChanged $event): bool => $event->gameId === $game->id);
        Event::assertDispatchedTimes(GameChanged::class, 1);
        GameServer::tool(GameSessionTool::class, ['operation' => 'confirm_action', 'version' => $game->fresh()->version])->assertHasErrors();
        $this->assertSame(1, $game->actions()->count());
        Event::assertDispatchedTimes(GameChanged::class, 1);
    }

    public function test_stale_versions_invalid_actions_and_missing_versions_are_rejected(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        Event::fake([GameChanged::class]);
        GameServer::tool(GameSessionTool::class, ['operation' => 'stage_action', 'version' => $game->version + 1, 'action_id' => $actionId])->assertHasErrors();
        GameServer::tool(GameSessionTool::class, ['operation' => 'stage_action', 'version' => $game->version, 'action_id' => 'invalid'])->assertHasErrors();
        GameServer::tool(GameSessionTool::class, ['operation' => 'stage_action', 'action_id' => $actionId])->assertHasErrors();
        GameServer::tool(GameSessionTool::class, ['operation' => 'stage_action', 'version' => $game->version, 'action_id' => $actionId])->assertOk();
        $game->increment('version');
        GameServer::tool(GameSessionTool::class, ['operation' => 'confirm_action', 'version' => $game->version])->assertHasErrors();
        $this->assertNull($player->fresh()->homeland);
        $this->assertSame(0, $game->actions()->count());
        Event::assertNotDispatched(GameChanged::class);
    }

    public function test_inactive_player_cannot_stage_an_action(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        config(['game-mcp.user_id' => $game->players()->whereKeyNot($player->id)->sole()->user_id]);
        GameServer::tool(GameSessionTool::class, ['operation' => 'get_legal_actions'])->assertOk()->assertDontSee($actionId);
        GameServer::tool(GameSessionTool::class, ['operation' => 'stage_action', 'version' => $game->version, 'action_id' => $actionId])->assertHasErrors();
    }

    public function test_finishing_the_turn_also_requires_staging_and_confirmation(): void
    {
        [$game, $player, $actionId] = $this->createGameSession();
        Event::fake([GameChanged::class]);
        GameServer::tool(GameSessionTool::class, ['operation' => 'stage_action', 'version' => $game->version, 'action_id' => $actionId])->assertOk();
        GameServer::tool(GameSessionTool::class, ['operation' => 'confirm_action', 'version' => $game->version])->assertOk();
        $game->refresh();
        $state = $game->state;
        $state->round->phase = GamePhase::Actions;
        $state->round->hasTakenMainAction = true;
        $state->round->turnStartVersion = $game->version;
        $game->update(['state' => $state, 'phase' => GamePhase::Actions, 'active_game_player_id' => $player->id]);
        $before = $game->fresh()->getAttributes();

        GameServer::tool(GameSessionTool::class, ['operation' => 'get_legal_actions'])->assertOk()->assertSee('finish_turn');
        GameServer::tool(GameSessionTool::class, ['operation' => 'stage_action', 'version' => $game->version, 'action_id' => 'finish_turn'])->assertOk();
        GameServer::tool(GameSessionTool::class, ['operation' => 'rollback_action', 'version' => $game->version])->assertOk();
        $this->assertSame($before, $game->fresh()->getAttributes());
        GameServer::tool(GameSessionTool::class, ['operation' => 'stage_action', 'version' => $game->version, 'action_id' => 'finish_turn'])->assertOk();
        GameServer::tool(GameSessionTool::class, ['operation' => 'confirm_action', 'version' => $game->version])->assertOk();
        $this->assertNotSame($player->id, $game->fresh()->active_game_player_id);
        $this->assertFalse($game->fresh()->state->round->hasTakenMainAction);
        Event::assertDispatchedTimes(GameChanged::class, 2);
    }

    /** @return array{Game, GamePlayer, string} */
    private function createGameSession(): array
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'mcp-game']);
        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => $index + 1]);
        }
        $this->actingAs($users[0])->post(route('games.start', $game))->assertNoContent();
        $this->app['auth']->forgetGuards();
        $game->refresh();
        $game->actions()->delete();
        $player = $game->players()->findOrFail($game->active_game_player_id);
        config(['game-mcp.user_id' => $player->user_id]);
        Cache::forget('game-mcp:staged:'.$player->id);
        $option = app(GameActionOptionFinder::class)->execute($game->state, $player->id)[0];

        return [$game, $player, hash('sha256', json_encode([$option->type()->value, $option->toArray()], JSON_THROW_ON_ERROR))];
    }
}
