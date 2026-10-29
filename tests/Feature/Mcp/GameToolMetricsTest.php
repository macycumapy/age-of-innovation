<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Game\Enums\GameStatus;
use App\Mcp\Server\GameServer;
use App\Mcp\Stores\GameContextSnapshotStore;
use App\Mcp\Tools\GetGameComponentTool;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Context;
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
        $this->assertSame(['tool', 'game_id', 'user_id', 'duration_ms', 'input_bytes', 'response_bytes', 'status', 'has_context_id', 'response_format', 'full_snapshot_reason', 'actions_count'], array_keys($metric));
        $this->assertFalse($metric['has_context_id']);
        $this->assertNull($metric['response_format']);
        $this->assertNull($metric['full_snapshot_reason']);
        $this->assertNull($metric['actions_count']);
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

    public function test_wait_metrics_distinguish_full_snapshot_reasons_and_deltas_without_logging_ids(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->active()->create();
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['active_game_player_id' => $player->id]);
        $token = $user->createToken('test', ['game:mcp'])->plainTextToken;
        config(['game-mcp.metrics.enabled' => true]);
        $records = [];
        $this->captureMetrics($records);
        $baseline = $this->callTool($token, 'get_turn_context', ['game_id' => $game->id]);
        $this->assertSame('explicit_read', $records[0]['full_snapshot_reason']);
        $this->assertSame('compact-v2', $records[0]['response_format']);
        $this->assertSame(count($baseline['actions']), $records[0]['actions_count']);
        $arguments = ['game_id' => $game->id, 'timeout_seconds' => 0];

        $this->callTool($token, 'wait_for_turn', $arguments);
        $this->assertSame('no_context_id', $records[1]['full_snapshot_reason']);
        $this->assertFalse($records[1]['has_context_id']);

        $this->callTool($token, 'wait_for_turn', [...$arguments, 'context_id' => str_repeat('a', 32)]);
        $this->assertSame('baseline_unavailable', $records[2]['full_snapshot_reason']);
        $this->assertTrue($records[2]['has_context_id']);

        $delta = $this->callTool($token, 'wait_for_turn', [...$arguments, 'context_id' => $baseline['context_id']]);
        $this->assertSame('context-delta-v1', $records[3]['response_format']);
        $this->assertNull($records[3]['full_snapshot_reason']);
        $this->assertSame(count($delta['context_update']['actions']), $records[3]['actions_count']);

        $state = $game->state;
        $state->round->number++;
        $game->update(['state' => $state, 'version' => 1]);
        $full = $this->callTool($token, 'wait_for_turn', [...$arguments, 'context_id' => $baseline['context_id']]);
        $this->assertSame('round_changed', $records[4]['full_snapshot_reason']);
        $this->assertSame('compact-v2', $records[4]['response_format']);

        $future = $full;
        unset($future['reason']);
        $future['version'] = 99;
        $id = app(GameContextSnapshotStore::class)->remember($future)['context_id'];
        $this->callTool($token, 'wait_for_turn', [...$arguments, 'context_id' => $id]);
        $this->assertSame('incompatible_baseline', $records[5]['full_snapshot_reason']);

        $large = $full;
        unset($large['reason']);
        $large['state'] = ['round' => ['number' => 2]];
        $id = app(GameContextSnapshotStore::class)->remember($large)['context_id'];
        $this->callTool($token, 'wait_for_turn', [...$arguments, 'context_id' => $id]);
        $this->assertSame('delta_not_smaller', $records[6]['full_snapshot_reason']);

        $this->callTool($token, 'wait_for_turn', [...$arguments, 'context_id' => $full['context_id']]);
        $this->assertNull($records[7]['full_snapshot_reason']);
        $this->assertFalse(Context::hasHidden('mcp.full_snapshot_reason'));
        $game->update(['active_game_player_id' => null]);
        $timeout = $this->callTool($token, 'wait_for_turn', [...$arguments, 'context_id' => $full['context_id']]);
        $this->assertSame(['reason' => 'timeout'], $timeout);
        $this->assertTrue($records[8]['has_context_id']);
        $this->assertNull($records[8]['response_format']);
        $this->assertNull($records[8]['full_snapshot_reason']);
        $this->assertNull($records[8]['actions_count']);
        $this->assertStringNotContainsString($baseline['context_id'], json_encode($records));
        $this->assertStringNotContainsString($token, json_encode($records));
        $this->assertArrayNotHasKey('full_snapshot_reason', $full);
        $game->update(['status' => GameStatus::Finished]);
        $this->callTool($token, 'wait_for_turn', $arguments);
        $this->assertSame('game_ended', $records[9]['full_snapshot_reason']);
        $this->assertSame('compact-v2', $records[9]['response_format']);
        $this->assertNull($records[9]['actions_count']);
        $this->assertCount(10, $records);
    }

    public function test_context_reason_is_not_inherited_by_the_next_call(): void
    {
        config(['game-mcp.metrics.enabled' => true, 'game-mcp.user_id' => User::factory()->create()->id]);
        $records = [];
        $this->captureMetrics($records);
        Context::addHidden('mcp.full_snapshot_reason', 'round_changed');
        GameServer::tool(GetGameComponentTool::class, ['type' => 'innovation', 'id' => 'league_of_cities'])->assertOk();
        $this->assertNull($records[0]['full_snapshot_reason']);
        $this->assertFalse(Context::hasHidden('mcp.full_snapshot_reason'));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $token, string $name, array $arguments): array
    {
        return $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
        ])->assertOk()->assertJsonPath('result.isError', false)->json('result.structuredContent');
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
