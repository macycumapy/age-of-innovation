<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Server\GameServer;
use App\Mcp\Tools\GetGameComponentTool;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class GameToolMetricsTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_records_one_safe_metric_with_actual_response_size_and_token_owner(): void
    {
        $user = User::factory()->create();
        config(['game-mcp.metrics.enabled' => true, 'game-mcp.user_id' => 999]);
        $records = [];
        $this->captureMetrics($records);
        $arguments = ['type' => 'innovation', 'id' => 'league_of_cities', 'secret' => 'do-not-log'];
        $response = $this->withToken($user->createToken('test', ['game:mcp'])->plainTextToken)
            ->postJson('/mcp/game', [
                'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
                'params' => ['name' => 'get_component', 'arguments' => $arguments],
            ])->assertOk()->assertJsonPath('result.isError', false);

        $this->assertCount(1, $records);
        $metric = $records[0];
        $this->assertSame('get_component', $metric['tool']);
        $this->assertSame($user->id, $metric['user_id']);
        $this->assertNull($metric['game_id']);
        $this->assertSame('success', $metric['status']);
        $this->assertGreaterThanOrEqual(0, $metric['duration_ms']);
        $this->assertSame(strlen(json_encode($arguments, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $metric['input_bytes']);
        $this->assertSame(strlen(json_encode($response->json(), JSON_UNESCAPED_UNICODE)), $metric['response_bytes']);
        $this->assertSame(['tool', 'game_id', 'user_id', 'duration_ms', 'input_bytes', 'response_bytes', 'status'], array_keys($metric));
        $this->assertStringNotContainsString('do-not-log', json_encode($records));
        $this->assertStringNotContainsString('description', json_encode($records));
    }

    public function test_local_calls_and_validation_and_domain_errors_are_measured(): void
    {
        $user = User::factory()->create();
        config(['game-mcp.metrics.enabled' => true, 'game-mcp.user_id' => $user->id]);
        $records = [];
        $this->captureMetrics($records);

        GameServer::tool(GetGameComponentTool::class, ['type' => 'innovation', 'id' => 'league_of_cities'])->assertOk();
        GameServer::tool(GetGameComponentTool::class, [])->assertHasErrors();
        GameServer::tool(GetGameComponentTool::class, ['type' => 'innovation', 'id' => 'unknown'])->assertHasErrors();

        $this->assertCount(3, $records);
        $this->assertSame(['success', 'error', 'error'], array_column($records, 'status'));
        $this->assertSame([$user->id, $user->id, $user->id], array_column($records, 'user_id'));
        foreach ($records as $metric) {
            $this->assertGreaterThan(0, $metric['response_bytes']);
        }
    }

    public function test_timeout_is_measured_without_adding_state_to_the_response(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        config(['game-mcp.metrics.enabled' => true]);
        $records = [];
        $this->captureMetrics($records);

        $this->withToken($user->createToken('test', ['game:mcp'])->plainTextToken)
            ->postJson('/mcp/game', [
                'jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/call',
                'params' => ['name' => 'wait_for_turn', 'arguments' => ['game_id' => $game->id, 'timeout_seconds' => 0]],
            ])->assertOk()->assertJsonPath('result.structuredContent', ['reason' => 'timeout']);

        $this->assertCount(1, $records);
        $this->assertSame('timeout', $records[0]['status']);
        $this->assertSame($game->id, $records[0]['game_id']);
    }

    public function test_disabled_metrics_do_not_access_the_log_channel(): void
    {
        config(['game-mcp.metrics.enabled' => false, 'game-mcp.user_id' => User::factory()->create()->id]);
        Log::shouldReceive('channel')->never();
        GameServer::tool(GetGameComponentTool::class, ['type' => 'innovation', 'id' => 'league_of_cities'])->assertOk();
    }

    public function test_log_failure_does_not_change_a_successful_tool_response(): void
    {
        config(['game-mcp.metrics.enabled' => true, 'game-mcp.user_id' => User::factory()->create()->id]);
        Log::shouldReceive('channel')->once()->with('mcp')->andThrow(new RuntimeException('Disk unavailable'));
        GameServer::tool(GetGameComponentTool::class, ['type' => 'innovation', 'id' => 'league_of_cities'])->assertOk();
    }

    /** @param list<array<string, mixed>> $records */
    private function captureMetrics(array &$records): void
    {
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->withArgs(function (string $message, array $context) use (&$records): bool {
            $this->assertSame('mcp.tool_call', $message);
            $records[] = $context;

            return true;
        });
        Log::shouldReceive('channel')->with('mcp')->andReturn($logger);
    }
}
