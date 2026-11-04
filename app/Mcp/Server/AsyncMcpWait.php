<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Mcp\Data\WaitForGameTurnData;
use App\Mcp\Server\Services\AsyncGameChangeSignal;
use App\Mcp\Server\Services\GameWaitMetrics;
use Clue\React\Redis\Client;
use GuzzleHttp\Psr7\Response;
use GuzzleHttp\Psr7\Utils;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;
use RuntimeException;
use Throwable;

final class AsyncMcpWait
{
    /** @var Deferred<ResponseInterface> */
    private Deferred $result;

    /** @var PromiseInterface<mixed>|null */
    private ?PromiseInterface $backendPending = null;

    /** @var PromiseInterface<mixed>|null */
    private ?PromiseInterface $signalPending = null;

    private ?Client $client = null;

    private ?TimerInterface $deadlineTimer = null;

    private ?TimerInterface $pollTimer = null;

    private bool $done = false;

    private bool $checking = false;

    private bool $initialized = false;

    private bool $polling = false;

    private bool $expired = false;

    private ?int $gameId = null;

    private string $cursor = '0-0';

    private int $startedAt;

    private int $checks = 0;

    public function __construct(private McpBackend $backend, private AsyncGameChangeSignal $signals, private GameWaitMetrics $metrics, private LoopInterface $loop, private RequestInterface $request, private WaitForGameTurnData $data)
    {
    }

    /** @return PromiseInterface<ResponseInterface> */
    public function start(): PromiseInterface
    {
        $this->startedAt = hrtime(true);
        $this->result = new Deferred(function (): void {
            if (! $this->done) {
                $this->done = true;
                $this->cleanup();
                $this->record('cancelled');
                $this->result->reject(new RuntimeException('MCP request cancelled.'));
            }
        });
        $this->deadlineTimer = $this->loop->addTimer($this->data->timeoutSeconds, function (): void {
            $this->expired = true;
            if (! $this->done && ! $this->checking) {
                $this->signalPending?->cancel();
                $this->client?->close();
                $this->client = null;
                $this->check();
            }
        });
        $this->check('initial');

        return $this->result->promise();
    }

    private function check(string $mode = 'refresh'): void
    {
        if ($this->done || $this->checking) {
            return;
        }
        $this->checking = true;
        $this->checks++;
        $this->backendPending = $this->backend->send($this->request, $mode)->then(function (ResponseInterface $response): void {
            if ($this->done) {
                return;
            }
            $this->checking = false;
            $body = json_decode((string) $response->getBody(), true);
            $payload = $body['result']['structuredContent'] ?? [];
            $id = $response->getHeaderLine(AsyncMcpProbe::GAME_HEADER);
            if (ctype_digit($id) && (int) $id > 0) {
                $this->gameId = (int) $id;
            }
            if ($response->getStatusCode() !== 200 || ($payload['reason'] ?? null) !== 'timeout' || $this->expired) {
                $this->finish($response, $payload['reason'] ?? 'error');

                return;
            }
            if ($this->gameId === null) {
                throw new RuntimeException('Missing authenticated game context.');
            }
            $rpc = json_decode((string) $this->request->getBody(), true, flags: JSON_THROW_ON_ERROR);
            $rpc['params']['arguments']['game_id'] = $this->gameId;
            $this->request = $this->request->withBody(Utils::streamFor(json_encode($rpc, JSON_THROW_ON_ERROR)));

            if (! $this->initialized) {
                $this->initialize();
            } elseif ($this->polling) {
                $this->pollTimer = $this->loop->addTimer(1, fn () => $this->check());
            } else {
                $this->wait();
            }
        })->then(null, fn (Throwable $exception) => $this->fail($exception));
    }

    private function initialize(): void
    {
        $this->initialized = true;
        if (! config('game-mcp.wait.enabled')) {
            $this->polling = true;
            $this->metrics->transport($this->gameId, 'poll', 'disabled');
            $this->check();

            return;
        }
        $this->signalPending = $this->signals->connect()->then(function (Client $client): PromiseInterface {
            if ($this->done || $this->expired) {
                $client->close();

                throw new RuntimeException('MCP wait already finished.');
            }
            $this->client = $client;

            return $this->signals->cursor($client, $this->gameId);
        })->then(function (string $cursor): void {
            if (! $this->done && ! $this->expired) {
                $this->cursor = $cursor;
                $this->metrics->transport($this->gameId, 'redis');
                /** The captured cursor precedes this last database check, retaining concurrent events. */
                $this->check();
            }
        })->then(null, fn (Throwable $exception) => $this->redisFailure('async_cursor', $exception));
    }

    private function wait(): void
    {
        $milliseconds = max(1, (int) ceil($this->data->timeoutSeconds * 1000 - (hrtime(true) - $this->startedAt) / 1_000_000));
        $this->signalPending = $this->signals->wait($this->client, $this->gameId, $this->cursor, $milliseconds)
            ->then(function (string $cursor): void {
                if ($this->done || $this->expired) {
                    return;
                }
                $this->expired = $cursor === $this->cursor;
                $this->cursor = $cursor;
                $this->check();
            })->then(null, fn (Throwable $exception) => $this->redisFailure('async_wait', $exception));
    }

    private function redisFailure(string $operation, Throwable $exception): void
    {
        if ($this->done || $this->expired) {
            return;
        }
        $this->polling = true;
        $client = $this->client;
        $this->client = null;
        $client?->close();
        $this->metrics->redisError($this->gameId, $operation, $exception);
        $this->metrics->transport($this->gameId, 'poll', 'redis_unavailable');
        $this->check();
    }

    private function finish(ResponseInterface $response, string $reason): void
    {
        if ($this->done) {
            return;
        }
        $this->done = true;
        $this->cleanup();
        $this->record($reason);
        $this->result->resolve($response);
    }

    private function fail(Throwable $exception): void
    {
        $rpc = json_decode((string) $this->request->getBody(), true);
        $id = $rpc['id'] ?? null;
        $this->finish(new Response(503, ['Content-Type' => 'application/json'], json_encode([
            'jsonrpc' => '2.0', 'id' => is_int($id) || is_string($id) ? $id : null,
            'error' => ['code' => -32603, 'message' => 'MCP backend unavailable.'],
        ], JSON_THROW_ON_ERROR)), 'error');
    }

    private function record(string $reason): void
    {
        if ($this->gameId !== null) {
            $this->metrics->asyncWait($this->gameId, $reason, (hrtime(true) - $this->startedAt) / 1_000_000, $this->checks);
        }
    }

    private function cleanup(): void
    {
        if ($this->deadlineTimer !== null) {
            $this->loop->cancelTimer($this->deadlineTimer);
            $this->deadlineTimer = null;
        }
        if ($this->pollTimer !== null) {
            $this->loop->cancelTimer($this->pollTimer);
            $this->pollTimer = null;
        }
        $backend = $this->backendPending;
        $signal = $this->signalPending;
        $client = $this->client;
        $this->backendPending = $this->signalPending = null;
        $this->client = null;
        $backend?->cancel();
        $signal?->cancel();
        $client?->close();
    }
}
