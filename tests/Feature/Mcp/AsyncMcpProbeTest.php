<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Server\AsyncMcpProbe;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Sleep;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class AsyncMcpProbeTest extends TestCase
{
    use RefreshDatabase;

    private string $defaultGuard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->defaultGuard = config('auth.defaults.guard');
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    public function test_signed_probes_check_immediately_and_do_not_leak_context_into_the_next_request(): void
    {
        [$game, $token] = $this->createGameSession();
        Sleep::fake();
        $this->probe($token, ['game_id' => $game->id])->assertOk()
            ->assertHeader(AsyncMcpProbe::GAME_HEADER, (string) $game->id)
            ->assertJsonPath('result.structuredContent', ['reason' => 'timeout']);
        Sleep::assertNeverSlept();
        $this->assertNull(Context::getHidden(AsyncMcpProbe::CONTEXT));
        $this->assertNull(Context::getHidden(AsyncMcpProbe::GAME_CONTEXT));
        $this->withToken($token)->postJson('/mcp/game', $this->rpc(['game_id' => $game->id, 'timeout_seconds' => 1]))
            ->assertOk()->assertHeaderMissing(AsyncMcpProbe::GAME_HEADER);
        Sleep::assertSleptTimes(1);
    }

    public function test_a_signed_probe_still_validates_the_original_wait_arguments(): void
    {
        [$game, $token] = $this->createGameSession();
        $this->probe($token, ['game_id' => $game->id, 'timeout_seconds' => 31])
            ->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_a_forged_probe_cannot_enter_the_internal_path(): void
    {
        [$game, $token] = $this->createGameSession();
        $this->withToken($token)->postJson('/mcp/game', $this->rpc(['game_id' => $game->id]), [AsyncMcpProbe::HEADER => 'refresh:forged'])
            ->assertForbidden();
    }

    public function test_refresh_probes_recheck_authentication_membership_and_token_abilities(): void
    {
        [$game, $token, $player] = $this->createGameSession();
        $this->probe('', ['game_id' => $game->id], 'refresh')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $wrongAbilities = $player->user->createToken('wrong', ['other'])->plainTextToken;
        $this->probe($wrongAbilities, ['game_id' => $game->id], 'refresh')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $player->delete();
        $this->probe($token, ['game_id' => $game->id], 'refresh')->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_only_initial_probes_count_against_the_request_rate_limit(): void
    {
        [$game, $token] = $this->createGameSession();
        $first = $this->probe($token, ['game_id' => $game->id]);
        $remaining = (int) $first->headers->get('X-RateLimit-Remaining');
        $this->probe($token, ['game_id' => $game->id], 'refresh')->assertHeaderMissing('X-RateLimit-Remaining');
        $this->probe($token, ['game_id' => $game->id])->assertHeader('X-RateLimit-Remaining', (string) ($remaining - 1));
    }

    public function test_a_ready_probe_returns_a_fresh_context_and_then_a_delta(): void
    {
        [$game, $token, $player] = $this->createGameSession();
        $game->update(['active_game_player_id' => $player->id, 'version' => 1]);
        $response = $this->probe($token, ['game_id' => $game->id]);
        $response->assertOk()->assertJsonPath('result.structuredContent.reason', 'your_turn')
            ->assertJsonPath('result.structuredContent.version', 1)
            ->assertJsonStructure(['result' => ['structuredContent' => ['actions', 'state', 'context_id']]]);
        $game->update(['version' => 2]);
        $this->probe($token, ['game_id' => $game->id, 'context_id' => $response->json('result.structuredContent.context_id')], 'refresh')
            ->assertOk()->assertJsonPath('result.structuredContent.context_update.base_version', 1)
            ->assertJsonPath('result.structuredContent.context_update.version', 2);
    }

    /** @param array<string, mixed> $arguments */
    private function probe(string $token, array $arguments, string $mode = 'initial'): TestResponse
    {
        $rpc = $this->rpc($arguments);
        $body = json_encode($rpc, JSON_THROW_ON_ERROR);
        $signature = app(AsyncMcpProbe::class)->sign($body, $token === '' ? '' : 'Bearer '.$token, '', $mode);

        $this->app['auth']->forgetGuards();
        $this->app['auth']->shouldUse($this->defaultGuard);

        return $this->call('POST', '/mcp/game', server: [
            'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => $token === '' ? '' : 'Bearer '.$token,
            'HTTP_'.strtoupper(str_replace('-', '_', AsyncMcpProbe::HEADER)) => $signature,
        ], content: $body);
    }

    /** @return array{Game, string, GamePlayer} */
    private function createGameSession(): array
    {
        $game = Game::factory()->active()->create();
        $player = GamePlayer::factory()->for($game)->for(User::factory())->create();

        return [$game, $player->user->createToken('async', ['game:mcp'])->plainTextToken, $player];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function rpc(array $arguments): array
    {
        return ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => 'wait_for_turn', 'arguments' => $arguments]];
    }
}
