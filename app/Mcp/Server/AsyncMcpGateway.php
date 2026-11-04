<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Mcp\Server\Services\AsyncGameChangeSignal;
use App\Mcp\Server\Services\GameWaitMetrics;
use App\Mcp\Validators\WaitForGameTurnValidator;
use GuzzleHttp\Psr7\Message;
use GuzzleHttp\Psr7\Response;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use React\EventLoop\LoopInterface;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use Throwable;

final class AsyncMcpGateway
{
    public function __construct(private McpBackend $backend, private AsyncGameChangeSignal $signals, private GameWaitMetrics $metrics, private LoopInterface $loop, private WaitForGameTurnValidator $validator)
    {
    }

    /** @return PromiseInterface<string> */
    public function handle(RequestInterface $request): PromiseInterface
    {
        $origin = $request->getHeaderLine('Origin');
        if ($origin !== '' && ! in_array($origin, config('game-mcp.async.allowed_origins'), true)) {
            return resolve($this->wire(new Response(403)));
        }
        $rpc = json_decode((string) $request->getBody(), true);
        $waiting = $request->getMethod() === 'POST' && is_array($rpc)
            && ($rpc['method'] ?? null) === 'tools/call' && ($rpc['params']['name'] ?? null) === 'wait_for_turn';

        if ($waiting) {
            try {
                $arguments = $rpc['params']['arguments'] ?? [];
                if (! is_array($arguments)) {
                    return $this->forward($request);
                }
                $data = $this->validator->validate(new Request($arguments));
            } catch (ValidationException) {
                return $this->forward($request);
            }
            if ($data->timeoutSeconds > 0) {
                return (new AsyncMcpWait($this->backend, $this->signals, $this->metrics, $this->loop, $request, $data))
                    ->start()->then(
                        fn (ResponseInterface $response): string => $this->wire($response),
                        fn (Throwable $exception): string => $this->wire(new Response(503))
                    );
            }
        }

        return $this->forward($request);
    }

    /** @return PromiseInterface<string> */
    private function forward(RequestInterface $request): PromiseInterface
    {
        return $this->backend->send($request)->then(
            fn (ResponseInterface $response): string => $this->wire($response),
            fn (Throwable $exception): string => $this->wire(new Response(503, ['Content-Type' => 'application/json'], '{"error":"MCP backend unavailable."}'))
        );
    }

    private function wire(ResponseInterface $response): string
    {
        return Message::toString($response
            ->withoutHeader(AsyncMcpProbe::GAME_HEADER)->withoutHeader(AsyncMcpProbe::HEADER)
            ->withoutHeader('Transfer-Encoding')->withoutHeader('Connection')
            ->withHeader('Connection', 'close')->withHeader('Content-Length', (string) strlen((string) $response->getBody())));
    }
}
