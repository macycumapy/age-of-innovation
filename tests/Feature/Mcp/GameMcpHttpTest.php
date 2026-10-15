<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\User\Actions\IssueTokenAction;
use App\Domain\User\Actions\RevokeTokenAction;
use App\Domain\User\Data\IssueTokenData;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GameMcpHttpTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_mcp_requires_a_bearer_token_even_with_a_web_session(): void
    {
        $user = User::factory()->create();
        config(['game-mcp.user_id' => $user->id]);
        $this->post('/mcp/game')->assertUnauthorized();
        $this->actingAs($user)->postJson('/mcp/game', [])->assertUnauthorized();
        $this->postJson('/mcp/game?api_token='.Str::random(64), [])->assertUnauthorized();
    }

    public function test_bearer_token_initializes_the_server_and_lists_tools(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);
        $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [],
        ])->assertOk()->assertJsonPath('result.serverInfo.name', 'Game Server');
        $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list',
        ])->assertOk()->assertJsonPath('result.tools.0.name', 'game-session-tool');
    }

    public function test_http_join_uses_the_token_owner_instead_of_the_local_user(): void
    {
        $localUser = User::factory()->create();
        $remoteUser = User::factory()->create();
        $game = Game::factory()->create();
        config(['game-mcp.user_id' => $localUser->id]);

        $this->callTool($this->token($remoteUser), ['operation' => 'join_game', 'game_id' => $game->id, 'user_id' => $localUser->id])
            ->assertOk()->assertJsonPath('result.isError', false);

        $this->assertSame($remoteUser->id, $game->players()->sole()->user_id);
        $this->assertSame(0, $localUser->gamePlayers()->count());
    }

    public function test_http_cannot_read_another_users_game_or_use_local_configuration_as_fallback(): void
    {
        $owner = User::factory()->create();
        $remoteUser = User::factory()->create();
        $player = GamePlayer::factory()->create(['user_id' => $owner->id]);
        config(['game-mcp.user_id' => $owner->id]);

        $this->callTool($this->token($remoteUser), ['operation' => 'get_state', 'game_id' => $player->game_id])
            ->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_invalid_and_revoked_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $token = $this->token($user);
        $this->withToken(Str::random(64))->postJson('/mcp/game', [])->assertUnauthorized();
        $this->artisan('game:mcp-token', ['user' => $user->id, '--revoke' => true])->assertSuccessful();
        $this->assertSame(0, $user->tokens()->where('name', 'game-mcp')->count());
        $this->withToken($token)->postJson('/mcp/game', [])->assertUnauthorized();
    }

    public function test_issuing_a_token_stores_only_a_hidden_hash_and_rotates_the_previous_token(): void
    {
        $this->assertFalse(Schema::hasColumn('users', 'game_mcp_token_hash'));
        $user = User::factory()->create();
        $oldToken = app(IssueTokenAction::class)->execute($user, new IssueTokenData('game-mcp', ['game:mcp']));
        $newToken = $this->token($user);
        $this->assertNotSame($oldToken, $newToken);
        $storedToken = $user->tokens()->sole();
        $this->assertSame(hash('sha256', explode('|', $newToken, 2)[1]), $storedToken->token);
        $this->assertSame(['game:mcp'], $storedToken->abilities);
        $this->assertArrayNotHasKey('token', $storedToken->toArray());
        $this->withToken($oldToken)->postJson('/mcp/game', [])->assertUnauthorized();
        $this->withToken($newToken)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [],
        ])->assertOk();
    }

    public function test_token_command_rejects_missing_or_invalid_users(): void
    {
        $this->artisan('game:mcp-token', ['user' => 999999])->assertFailed();
        $this->artisan('game:mcp-token', ['user' => 'invalid'])->assertFailed();
    }

    public function test_tokens_without_the_game_ability_are_forbidden(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('other', ['profile:read'])->plainTextToken;

        $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [],
        ])->assertForbidden();
    }

    public function test_expired_sanctum_tokens_are_rejected(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('game-mcp', ['game:mcp'], now()->subMinute())->plainTextToken;

        $this->withToken($token)->postJson('/mcp/game', [])->assertUnauthorized();
    }

    public function test_rotation_and_revocation_preserve_unrelated_sanctum_tokens(): void
    {
        $user = User::factory()->create();
        $otherToken = $user->createToken('other', ['profile:read']);
        $this->token($user);
        $this->token($user);
        $this->assertModelExists($otherToken->accessToken);

        $this->artisan('game:mcp-token', ['user' => $user->id, '--revoke' => true])->assertSuccessful();
        $this->assertModelExists($otherToken->accessToken);
        $this->assertSame(1, $user->tokens()->count());
    }

    public function test_revoke_action_only_revokes_the_requested_users_game_tokens(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        app(IssueTokenAction::class)->execute($user, new IssueTokenData('game-mcp', ['game:mcp']));
        $otherToken = app(IssueTokenAction::class)->execute($otherUser, new IssueTokenData('game-mcp', ['game:mcp']));
        $unrelatedToken = $user->createToken('other', ['profile:read']);

        app(RevokeTokenAction::class)->execute($user, 'game-mcp');

        $this->assertSame(0, $user->tokens()->where('name', 'game-mcp')->count());
        $this->assertModelExists($unrelatedToken->accessToken);
        $this->withToken($otherToken)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [],
        ])->assertOk();
    }

    public function test_issue_action_uses_dto_name_and_abilities_and_rotates_only_matching_tokens(): void
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $oldToken = $user->createToken('custom-client', ['old:ability'])->accessToken;
        $gameToken = $user->createToken('game-mcp', ['game:mcp'])->accessToken;
        $otherUserToken = $otherUser->createToken('custom-client', ['old:ability'])->accessToken;

        $plainTextToken = app(IssueTokenAction::class)->execute(
            $user,
            new IssueTokenData('custom-client', ['profile:read', 'profile:update']),
        );

        $token = $user->tokens()->where('name', 'custom-client')->sole();
        $this->assertSame(['profile:read', 'profile:update'], $token->abilities);
        $this->assertSame(hash('sha256', explode('|', $plainTextToken, 2)[1]), $token->token);
        $this->assertModelMissing($oldToken);
        $this->assertModelExists($gameToken);
        $this->assertModelExists($otherUserToken);
    }

    public function test_revoke_action_accepts_any_token_name(): void
    {
        $user = User::factory()->create();
        $customToken = $user->createToken('custom-client', ['profile:read'])->accessToken;
        $gameToken = $user->createToken('game-mcp', ['game:mcp'])->accessToken;

        app(RevokeTokenAction::class)->execute($user, 'custom-client');

        $this->assertModelMissing($customToken);
        $this->assertModelExists($gameToken);
    }

    private function token(User $user): string
    {
        $this->assertSame(0, Artisan::call('game:mcp-token', ['user' => $user->id]));

        return trim(Artisan::output());
    }

    /** @param array<string, mixed> $arguments */
    private function callTool(string $token, array $arguments): TestResponse
    {
        return $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'game-session-tool', 'arguments' => $arguments],
        ]);

    }
}
