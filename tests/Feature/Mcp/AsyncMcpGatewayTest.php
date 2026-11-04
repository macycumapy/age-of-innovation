<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Server\AsyncMcpGateway;
use App\Mcp\Server\AsyncMcpProbe;
use App\Mcp\Server\McpBackend;
use App\Mcp\Server\Services\AsyncGameChangeSignal;
use App\Mcp\Server\Services\GameWaitMetrics;
use App\Mcp\Validators\WaitForGameTurnValidator;
use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use Mockery;
use Psr\Http\Message\RequestInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;

use function React\Promise\reject;
use function React\Promise\resolve;

use RuntimeException;
use Tests\TestCase;

final class AsyncMcpGatewayTest extends TestCase
{
    private StreamSelectLoop $loop;

    private array $calls = [];

    private array $waits = [];

    private bool $ready = false;

    private bool $redisFails = false;

    private bool $unauthorized = false;

    private bool $readyDuringCursor = false;

    private int $closed = 0;

    protected function setUp(): void
    {
        parent::setUp();
        config(['game-mcp.wait.enabled' => true, 'game-mcp.metrics.enabled' => false, 'game-mcp.async.allowed_origins' => ['http://localhost'], 'database.redis.game_signals.username' => null, 'database.redis.game_signals.password' => null]);
        $this->loop = new StreamSelectLoop();
    }

    public function test_four_waits_do_not_block_a_fast_request_and_each_keeps_its_rpc_id(): void
    {
        $gateway = $this->gateway();
        $responses = [];
        for ($id = 1; $id <= 4; $id++) {
            $gateway->handle($this->request($id))->then(function (string $response) use (&$responses, $id): void {
                $responses[$id] = $response;
            });
        }
        $this->assertCount(4, $this->waits);
        $this->assertSame([], $responses);
        $fast = null;
        $gateway->handle($this->request(99, 'get_state'))->then(function (string $response) use (&$fast): void {
            $fast = $response;
        });
        $this->assertStringContainsString('"id":99', $fast);
        $this->assertSame([], $responses);
        $this->ready = true;
        foreach ($this->waits as $wait) {
            $wait->resolve([['stream', [['1-0', ['changed', '1']]]]]);
        }
        $this->assertCount(4, $responses);
        foreach ($responses as $id => $wire) {
            $response = Message::parseResponse($wire);
            $json = json_decode((string) $response->getBody(), true);
            $this->assertSame($id, $json['id']);
            $this->assertSame('your_turn', $json['result']['structuredContent']['reason']);
            $this->assertFalse($response->hasHeader(AsyncMcpProbe::GAME_HEADER));
        }
        $this->assertSame(4, $this->closed);
        foreach ($this->calls as $call) {
            if ($call['probe'] === 'refresh') {
                $this->assertSame(7, $call['rpc']['params']['arguments']['game_id']);
            }
        }
        $this->loop->run();
    }

    public function test_the_deadline_rechecks_the_backend_and_closes_the_redis_wait(): void
    {
        $response = null;
        $this->gateway()->handle($this->request(1, arguments: ['timeout_seconds' => 1]))
            ->then(function (string $wire) use (&$response): void {
                $response = Message::parseResponse($wire);
            });
        $this->loop->run();
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['reason' => 'timeout'], json_decode((string) $response->getBody(), true)['result']['structuredContent']);
        $this->assertCount(3, $this->calls);
        $this->assertSame(['initial', 'refresh', 'refresh'], array_column($this->calls, 'probe'));
        $this->assertSame(1, $this->closed);
    }

    public function test_redis_failure_falls_back_to_an_async_timer(): void
    {
        $this->redisFails = true;
        $gateway = $this->gateway();
        $response = null;
        $gateway->handle($this->request(1, arguments: ['timeout_seconds' => 1]))
            ->then(function (string $wire) use (&$response): void {
                $response = $wire;
            });
        $this->assertNull($response);
        $fast = null;
        $gateway->handle($this->request(99, 'get_state'))->then(function (string $wire) use (&$fast): void {
            $fast = $wire;
        });
        $this->assertNotNull($fast);
        $this->ready = true;
        $this->loop->run();
        $this->assertStringContainsString('your_turn', $response);
    }

    public function test_zero_timeout_and_invalid_arguments_are_forwarded_without_creating_waits(): void
    {
        $gateway = $this->gateway();
        $gateway->handle($this->request(1, arguments: ['timeout_seconds' => 0]));
        $gateway->handle($this->request(2, arguments: ['timeout_seconds' => 31]));
        $this->assertSame([null, null], array_column($this->calls, 'probe'));
        $this->assertSame([], $this->waits);
    }

    public function test_unknown_origins_are_rejected_before_forwarding(): void
    {
        $response = null;
        $this->gateway()->handle($this->request(1)->withHeader('Origin', 'http://untrusted.example'))
            ->then(function (string $wire) use (&$response): void {
                $response = Message::parseResponse($wire);
            });
        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame([], $this->calls);
    }

    public function test_cancellation_closes_waits_and_removes_the_deadline_timer(): void
    {
        $promise = $this->gateway()->handle($this->request(1));
        $this->assertCount(1, $this->waits);
        $promise->cancel();
        $this->loop->run();
        $this->assertSame(1, $this->closed);
        $this->assertCount(2, $this->calls);
    }

    public function test_an_inflight_probe_is_cancelled_when_the_client_disconnects(): void
    {
        $cancelled = false;
        $pending = new Deferred(function () use (&$cancelled): void {
            $cancelled = true;
        });
        $backend = Mockery::mock(McpBackend::class);
        $backend->shouldReceive('send')->once()->andReturn($pending->promise());
        $this->gateway($backend)->handle($this->request(1))->cancel();
        $this->loop->run();
        $this->assertTrue($cancelled);
    }

    public function test_an_unauthorized_probe_is_returned_without_waiting(): void
    {
        $backend = Mockery::mock(McpBackend::class);
        $backend->shouldReceive('send')->once()->andReturn(resolve(new Response(401, ['WWW-Authenticate' => 'Bearer'])));
        $response = null;
        $this->gateway($backend)->handle($this->request(1))->then(function (string $wire) use (&$response): void {
            $response = Message::parseResponse($wire);
        });
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame('Bearer', $response->getHeaderLine('WWW-Authenticate'));
        $this->assertSame([], $this->waits);
        $this->loop->run();
    }

    public function test_revoked_authorization_is_checked_after_a_notification(): void
    {
        $response = null;
        $this->gateway()->handle($this->request(1))->then(function (string $wire) use (&$response): void {
            $response = Message::parseResponse($wire);
        });
        $this->unauthorized = true;
        $this->waits[0]->resolve([['stream', [['1-0', ['changed', '1']]]]]);
        $this->assertSame(401, $response->getStatusCode());
        $this->assertSame(1, $this->closed);
        $this->loop->run();
    }

    public function test_a_change_during_cursor_capture_is_caught_before_blocking(): void
    {
        $this->readyDuringCursor = true;
        $response = null;
        $this->gateway()->handle($this->request(1))->then(function (string $wire) use (&$response): void {
            $response = $wire;
        });
        $this->assertStringContainsString('your_turn', $response);
        $this->assertSame([], $this->waits);
        $this->loop->run();
    }

    private function gateway(?McpBackend $backend = null): AsyncMcpGateway
    {
        if ($backend === null) {
            $backend = Mockery::mock(McpBackend::class);
            $backend->shouldReceive('send')->andReturnUsing(function (RequestInterface $request, ?string $probe = null) {
                $rpc = json_decode((string) $request->getBody(), true);
                $this->calls[] = ['rpc' => $rpc, 'probe' => $probe];
                if ($this->unauthorized) {
                    return resolve(new Response(401));
                }
                return resolve(new Response(200, [AsyncMcpProbe::GAME_HEADER => '7', 'Content-Type' => 'application/json'], json_encode([
                    'jsonrpc' => '2.0', 'id' => $rpc['id'], 'result' => ['isError' => false, 'structuredContent' => ['reason' => $this->ready || $rpc['params']['name'] !== 'wait_for_turn' ? 'your_turn' : 'timeout']],
                ], JSON_THROW_ON_ERROR)));
            });
        }
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('createClient')->andReturnUsing(function () {
            if ($this->redisFails) {
                return reject(new RuntimeException('Redis unavailable.'));
            }
            $client = Mockery::mock(Client::class);
            $pending = null;
            $client->shouldReceive('select')->andReturn(resolve('OK'));
            $client->shouldReceive('xrevrange')->andReturnUsing(function () {
                $this->ready = $this->ready || $this->readyDuringCursor;
                return resolve([]);
            });
            $client->shouldReceive('xread')->andReturnUsing(function () use (&$pending) {
                $pending = new Deferred();
                $this->waits[] = $pending;
                return $pending->promise();
            });
            $client->shouldReceive('close')->andReturnUsing(function () use (&$pending): void {
                $this->closed++;
                $pending?->reject(new RuntimeException('Connection closed.'));
            });
            return resolve($client);
        });

        return new AsyncMcpGateway($backend, new AsyncGameChangeSignal($factory), app(GameWaitMetrics::class), $this->loop, new WaitForGameTurnValidator());
    }

    private function request(int $id, string $tool = 'wait_for_turn', array $arguments = []): Request
    {
        return new Request('POST', 'http://localhost/mcp/game', ['Authorization' => 'Bearer token', 'Content-Type' => 'application/json'], json_encode([
            'jsonrpc' => '2.0', 'id' => $id, 'method' => 'tools/call', 'params' => ['name' => $tool, 'arguments' => $arguments],
        ], JSON_THROW_ON_ERROR));
    }
}
