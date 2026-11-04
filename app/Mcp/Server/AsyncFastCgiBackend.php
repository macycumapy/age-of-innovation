<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Mcp\Data\FastCgiRequestData;
use Closure;
use GuzzleHttp\Psr7\Message;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use React\Socket\ConnectionInterface;
use React\Socket\ConnectorInterface;
use RuntimeException;
use Throwable;

final class AsyncFastCgiBackend implements McpBackend
{
    public function __construct(private ConnectorInterface $connector, private LoopInterface $loop, private AsyncMcpProbe $probes, private string $address)
    {
    }

    public function send(RequestInterface $request, ?string $probe = null): PromiseInterface
    {
        $state = new FastCgiRequestData();
        $cleanup = fn () => $state->close($this->loop);
        $deferred = new Deferred($cleanup);
        $fail = function (Throwable $exception) use ($state, $cleanup, $deferred): void {
            if (! $state->finished) {
                $cleanup();
                $deferred->reject($exception);
            }
        };
        $state->timer = $this->loop->addTimer($probe === null ? 30 : 5, fn () => $fail(new RuntimeException('MCP backend timed out.')));
        try {
            $state->connecting = $this->connector->connect($this->address);
        } catch (Throwable $exception) {
            $fail($exception);

            return $deferred->promise();
        }
        $state->connecting->then(function (ConnectionInterface $socket) use ($request, $probe, $state, $deferred, $fail): void {
            $state->connection = $socket;
            if ($state->finished) {
                $socket->close();

                return;
            }
            $socket->on('error', $fail);
            $socket->on('close', fn () => $fail(new RuntimeException('MCP backend closed before completing its response.')));
            $socket->on('data', fn (string $data) => $this->receive($data, $state, $deferred, $fail));
            try {
                $socket->write($this->packet($request, $probe));
            } catch (Throwable $exception) {
                $fail($exception);
            }
        }, $fail);

        return $deferred->promise();
    }

    /** @param Deferred<ResponseInterface> $result */
    private function receive(string $data, FastCgiRequestData $state, Deferred $result, Closure $fail): void
    {
        if ($state->finished) {
            return;
        }
        $state->buffer .= $data;
        try {
            while (strlen($state->buffer) >= 8) {
                $header = unpack('Cversion/Ctype/nid/nlength/Cpadding/Creserved', substr($state->buffer, 0, 8));
                if ($header === false) {
                    throw new RuntimeException('Invalid FastCGI header.');
                }
                $length = 8 + $header['length'] + $header['padding'];
                if (strlen($state->buffer) < $length) {
                    return;
                }
                if ($header['version'] !== 1 || $header['id'] !== 1) {
                    throw new RuntimeException('Invalid FastCGI response.');
                }
                $content = substr($state->buffer, 8, $header['length']);
                $state->buffer = substr($state->buffer, $length);
                if ($header['type'] === 6) {
                    $state->output .= $content;
                    if (strlen($state->output) > 16 * 1024 * 1024) {
                        throw new RuntimeException('MCP backend response is too large.');
                    }
                } elseif ($header['type'] === 3) {
                    $response = $this->response($state->output);
                    $state->close($this->loop);
                    $result->resolve($response);

                    return;
                }
            }
        } catch (Throwable $exception) {
            $fail($exception);
        }
    }

    private function packet(RequestInterface $request, ?string $probe): string
    {
        $body = (string) $request->getBody();
        $parameters = [
            'GATEWAY_INTERFACE' => 'CGI/1.1', 'SERVER_PROTOCOL' => 'HTTP/1.1',
            'SERVER_NAME' => $request->getUri()->getHost() ?: 'localhost', 'SERVER_PORT' => '80',
            'REMOTE_ADDR' => $request->getHeaderLine('X-Forwarded-For') ?: '127.0.0.1',
            'REQUEST_METHOD' => $request->getMethod(), 'REQUEST_URI' => '/mcp/game'.($request->getUri()->getQuery() === '' ? '' : '?'.$request->getUri()->getQuery()),
            'QUERY_STRING' => $request->getUri()->getQuery(), 'SCRIPT_NAME' => '/index.php', 'SCRIPT_FILENAME' => public_path('index.php'),
            'CONTENT_TYPE' => $request->getHeaderLine('Content-Type'), 'CONTENT_LENGTH' => (string) strlen($body), 'HTTP_PROXY' => '',
            'HTTPS' => $request->getHeaderLine('X-Forwarded-Proto') === 'https' ? 'on' : 'off',
        ];
        $excluded = ['content-length', 'content-type', 'connection', 'transfer-encoding', 'keep-alive', 'proxy', 'proxy-authorization', 'proxy-authenticate', 'upgrade', 'te', 'trailer', strtolower(AsyncMcpProbe::HEADER)];
        foreach ($request->getHeaders() as $name => $values) {
            if (! in_array(strtolower($name), $excluded, true)) {
                $parameters['HTTP_'.strtoupper(str_replace('-', '_', $name))] = implode(', ', $values);
            }
        }
        if ($probe !== null) {
            $parameters['HTTP_ACCEPT_ENCODING'] = 'identity';
            $parameters['HTTP_'.strtoupper(str_replace('-', '_', AsyncMcpProbe::HEADER))] = $this->probes->sign($body, $request->getHeaderLine('Authorization'), $request->getHeaderLine('Cookie'), $probe);
        }
        $encoded = '';
        foreach ($parameters as $name => $value) {
            $encoded .= $this->length(strlen($name)).$this->length(strlen($value)).$name.$value;
        }

        return $this->record(1, pack('nC', 1, 0).str_repeat("\0", 5))
            .$this->records(4, $encoded).$this->records(5, $body);
    }

    private function records(int $type, string $body): string
    {
        $records = '';
        foreach (str_split($body, 65535) as $chunk) {
            $records .= $this->record($type, $chunk);
        }

        return $records.$this->record($type, '');
    }

    private function record(int $type, string $content): string
    {
        return pack('CCnnCC', 1, $type, 1, strlen($content), 0, 0).$content;
    }

    private function length(int $length): string
    {
        return $length < 128 ? pack('C', $length) : pack('N', $length | 0x80000000);
    }

    private function response(string $output): ResponseInterface
    {
        $parts = explode("\r\n\r\n", $output, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException('Invalid MCP backend HTTP response.');
        }
        $status = preg_match('/(?:^|\r\n)Status:\s*(\d{3})[^\r\n]*/i', $parts[0], $matches) === 1 ? $matches[1] : '200';
        $headers = preg_replace('/(?:^|\r\n)Status:[^\r\n]*/i', '', $parts[0]);

        return Message::parseResponse("HTTP/1.1 {$status} Response\r\n".ltrim($headers, "\r\n")."\r\n\r\n".$parts[1]);
    }
}
