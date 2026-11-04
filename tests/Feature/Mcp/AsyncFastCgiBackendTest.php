<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Server\AsyncFastCgiBackend;
use App\Mcp\Server\AsyncMcpProbe;
use GuzzleHttp\Psr7\Request;
use Mockery;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\StreamSelectLoop;
use React\Promise\Deferred;

use function React\Promise\resolve;

use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use RuntimeException;
use Tests\TestCase;
use Throwable;

final class AsyncFastCgiBackendTest extends TestCase
{
    private array $handlers = [];

    private string $packet = '';

    private int $closed = 0;

    public function test_fragmented_responses_are_parsed_and_completed_connections_are_closed(): void
    {
        $backend = $this->backend();
        $response = null;
        $backend->send(new Request('POST', '/mcp/game', ['Authorization' => 'Bearer token'], '{}'))
            ->then(function (ResponseInterface $value) use (&$response): void {
                $response = $value;
            });
        $this->assertNull($response);
        foreach (str_split($this->reply(), 5) as $chunk) {
            ($this->handlers['data'])($chunk);
        }
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('{"ok":true}', (string) $response->getBody());
        $this->assertSame('7', $response->getHeaderLine(AsyncMcpProbe::GAME_HEADER));
        $this->assertSame(1, $this->closed);
        ($this->handlers['close'])();
        $this->assertSame(1, $this->closed);
    }

    public function test_probe_packets_preserve_large_bodies_and_authentication_and_replace_forged_headers(): void
    {
        $body = str_repeat('a', 100000);
        $backend = $this->backend();
        $promise = $backend->send(new Request('POST', '/mcp/game', [
            'Authorization' => 'Bearer token', 'Cookie' => 'session=value', AsyncMcpProbe::HEADER => 'refresh:forged', 'Proxy' => 'secret-proxy',
        ], $body), 'initial');
        $this->assertStringContainsString('Bearer token', $this->packet);
        $this->assertStringContainsString(app(AsyncMcpProbe::class)->sign($body, 'Bearer token', 'session=value', 'initial'), $this->packet);
        $this->assertStringNotContainsString('refresh:forged', $this->packet);
        $this->assertStringNotContainsString('secret-proxy', $this->packet);
        $stdin = '';
        $packet = $this->packet;
        while ($packet !== '') {
            $header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', substr($packet, 0, 8));
            if ($header['type'] === 5) {
                $stdin .= substr($packet, 8, $header['length']);
            }
            $packet = substr($packet, 8 + $header['length']);
        }
        $this->assertSame($body, $stdin);
        $promise->cancel();
        $this->assertSame(1, $this->closed);
    }

    public function test_an_invalid_protocol_response_rejects_without_replaying_the_request(): void
    {
        $failure = null;
        $this->backend()->send(new Request('POST', '/mcp/game', [], '{}'))
            ->then(null, function (Throwable $exception) use (&$failure): void {
                $failure = $exception;
            });
        ($this->handlers['data'])(pack('CCnnCC', 2, 6, 1, 0, 0, 0));
        $this->assertInstanceOf(RuntimeException::class, $failure);
        $this->assertSame(1, $this->closed);
    }

    public function test_cancellation_cancels_an_unfinished_connection_and_its_timeout(): void
    {
        $cancelled = false;
        $connection = new Deferred(function () use (&$cancelled): void {
            $cancelled = true;
        });
        $connector = Mockery::mock(ConnectorInterface::class);
        $connector->shouldReceive('connect')->once()->andReturn($connection->promise());
        $loop = new StreamSelectLoop();
        $backend = new AsyncFastCgiBackend($connector, $loop, app(AsyncMcpProbe::class), 'tcp://backend:9001');
        $backend->send(new Request('POST', '/mcp/game', [], '{}'))->cancel();
        $loop->run();
        $this->assertTrue($cancelled);
    }

    private function backend(): AsyncFastCgiBackend
    {
        $socket = Mockery::mock(ConnectionInterface::class);
        $socket->shouldReceive('on')->andReturnUsing(function (string $name, callable $handler) use ($socket): ConnectionInterface {
            $this->handlers[$name] = $handler;

            return $socket;
        });
        $socket->shouldReceive('write')->once()->andReturnUsing(function (string $packet): bool {
            $this->packet = $packet;
            return true;
        });
        $socket->shouldReceive('close')->andReturnUsing(function (): void {
            $this->closed++;
        });
        $connector = Mockery::mock(ConnectorInterface::class);
        $connector->shouldReceive('connect')->once()->with('tcp://backend:9001')->andReturn(resolve($socket));

        return new AsyncFastCgiBackend($connector, new StreamSelectLoop(), app(AsyncMcpProbe::class), 'tcp://backend:9001');
    }

    private function reply(): string
    {
        $stdout = "Status: 200 OK\r\nContent-Type: application/json\r\n".AsyncMcpProbe::GAME_HEADER.": 7\r\n\r\n{\"ok\":true}";

        return pack('CCnnCC', 1, 6, 1, strlen($stdout), 0, 0).$stdout
            .pack('CCnnCC', 1, 3, 1, 8, 0, 0).str_repeat("\0", 8);
    }
}
