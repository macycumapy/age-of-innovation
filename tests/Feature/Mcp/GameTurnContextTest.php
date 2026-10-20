<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\GameEngine\Setup\Actions\StartGameAction;
use App\Domain\GameSession\Data\GameTurnContextData;
use App\Domain\GameSession\Queries\GetGameStateQuery;
use App\Domain\GameSession\Queries\GetGameTurnContextQuery;
use App\Domain\GameSession\Queries\GetLegalGameActionsQuery;
use App\Mcp\Presentation\GameActionPresenter;
use App\Mcp\Presentation\GameStatePresenter;
use App\Mcp\Server\GameServer;
use App\Mcp\Tools\ConfirmGameActionTool;
use App\Mcp\Tools\GetGameTurnContextTool;
use App\Mcp\Tools\RollbackGameActionTool;
use App\Mcp\Tools\StageGameActionTool;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GameTurnContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_query_combines_existing_state_and_actions_without_changing_the_game(): void
    {
        [$game, $user] = $this->sessionGame();
        $before = $game->fresh()->getAttributes();
        $historyCount = $game->actions()->count();
        $result = app(GetGameTurnContextQuery::class)->execute($user, $game->id);

        $this->assertInstanceOf(GameTurnContextData::class, $result);
        $this->assertSame($result->view->version, $result->actions->version);
        $expectedState = app(GameStatePresenter::class)->present(app(GetGameStateQuery::class)->execute($user, $game->id));
        $expectedActions = app(GameActionPresenter::class)->legalActions(app(GetLegalGameActionsQuery::class)->execute($user, $game->id));
        $payload = app(GameStatePresenter::class)->turnContext($result);
        $this->assertSame([...$expectedState, 'actions' => $expectedActions['actions']], $payload);
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertSame($historyCount, $game->actions()->count());
        $this->assertArrayNotHasKey('turnStartSnapshot', $payload['state']);
        $this->assertArrayNotHasKey('townChoiceCheckpoint', $payload['state']);
        foreach ($payload['state']['players'] as $player) {
            $this->assertArrayNotHasKey('strategicPlan', $player);
        }
    }

    public function test_both_reads_are_inside_the_same_outer_transaction(): void
    {
        [$game, $user] = $this->sessionGame();
        $initialLevel = DB::transactionLevel();
        $gameReadLevels = [];
        DB::listen(function (QueryExecuted $query) use (&$gameReadLevels): void {
            if (preg_match('/select .* from [`"]games[`"] /i', $query->sql) === 1) {
                $gameReadLevels[] = DB::transactionLevel();
            }
        });

        app(GetGameTurnContextQuery::class)->execute($user, $game->id);

        $this->assertGreaterThanOrEqual(2, count($gameReadLevels));
        foreach ($gameReadLevels as $level) {
            $this->assertGreaterThanOrEqual($initialLevel + 2, $level);
        }
        $this->assertSame($initialLevel, DB::transactionLevel());
    }

    public function test_local_tool_returns_context_and_inactive_players_have_no_actions(): void
    {
        [$game] = $this->sessionGame();
        GameServer::tool(GetGameTurnContextTool::class, ['game_id' => $game->id])
            ->assertOk()->assertSee('compact-v2')->assertSee('actions');

        $other = $game->players()->whereKeyNot($game->active_game_player_id)->sole();
        $context = app(GetGameTurnContextQuery::class)->execute($other->user, $game->id);
        $payload = app(GameStatePresenter::class)->turnContext($context);
        $this->assertSame([], $payload['actions']);
        $this->assertSame($other->id, $payload['player_id']);
    }

    public function test_actions_from_context_support_staging_rollback_and_confirmation(): void
    {
        [$game, $user] = $this->sessionGame();
        $context = app(GetGameTurnContextQuery::class)->execute($user, $game->id);
        $payload = app(GameStatePresenter::class)->turnContext($context);
        $arguments = ['game_id' => $game->id, 'version' => $payload['version']];
        $stage = [...$arguments, 'action_id' => $payload['actions'][0]['action_id']];
        $before = $game->fresh()->getAttributes();
        $historyCount = $game->actions()->count();

        GameServer::tool(StageGameActionTool::class, $stage)->assertOk();
        $staged = app(GameStatePresenter::class)->turnContext(app(GetGameTurnContextQuery::class)->execute($user, $game->id));
        $this->assertSame($stage['action_id'], $staged['staged_action']['action_id']);
        $this->assertSame($before, $game->fresh()->getAttributes());
        GameServer::tool(RollbackGameActionTool::class, $arguments)->assertOk();
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertSame($historyCount, $game->actions()->count());
        GameServer::tool(StageGameActionTool::class, $stage)->assertOk();
        GameServer::tool(ConfirmGameActionTool::class, $arguments)->assertOk();
        $this->assertSame($historyCount + 1, $game->actions()->count());
        GameServer::tool(StageGameActionTool::class, $stage)->assertHasErrors();
    }

    public function test_http_context_uses_token_owner_and_single_version(): void
    {
        [$game, $user] = $this->sessionGame();
        config(['game-mcp.user_id' => User::factory()->create()->id]);
        $token = $user->createToken('test', ['game:mcp'])->plainTextToken;
        $response = $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_turn_context', 'arguments' => ['game_id' => $game->id]],
        ])->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.format', 'compact-v2')
            ->assertJsonPath('result.structuredContent.user_id', $user->id)
            ->assertJsonPath('result.structuredContent.version', $game->version)
            ->assertJsonStructure(['result' => ['structuredContent' => ['state', 'actions', 'staged_action']]]);

        $payload = $response->json('result.structuredContent');
        $this->assertNotEmpty($payload['actions']);
        $this->assertStringStartsWith('v'.$payload['version'].':', $payload['actions'][0]['action_id']);
        $this->assertSame($payload, json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR));
    }

    public function test_context_requires_membership_and_explicit_game_when_ambiguous(): void
    {
        [$game, $user] = $this->sessionGame();
        GamePlayer::factory()->create(['user_id' => $user->id]);
        GameServer::tool(GetGameTurnContextTool::class, [])->assertHasErrors()->assertSee('Specify game_id');
        GameServer::tool(GetGameTurnContextTool::class, ['game_id' => $game->id])->assertOk();
        config(['game-mcp.user_id' => User::factory()->create()->id]);
        GameServer::tool(GetGameTurnContextTool::class, ['game_id' => $game->id])->assertHasErrors();
    }

    public function test_http_context_requires_authentication_and_game_ability(): void
    {
        $request = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_turn_context', 'arguments' => ['game_id' => 1]]];
        $this->postJson('/mcp/game', $request)->assertUnauthorized();
        $token = User::factory()->create()->createToken('test', ['profile:read'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp/game', $request)->assertForbidden();
    }

    public function test_context_rejects_invalid_game_id(): void
    {
        GameServer::tool(GetGameTurnContextTool::class, ['game_id' => -1])->assertHasErrors();
    }

    /** @return array{Game, User} */
    private function sessionGame(): array
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'turn-context']);
        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => $index + 1]);
        }
        $game = app(StartGameAction::class)->execute($game, $users[0]);
        $user = $game->activeGamePlayer->user;
        config(['game-mcp.user_id' => $user->id]);

        return [$game, $user];
    }
}
