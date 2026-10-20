<?php

declare(strict_types=1);

namespace App\Mcp\Server\Methods;

use Generator;
use Illuminate\Log\LogManager;
use Laravel\Mcp\Server\Methods\CallTool;
use Laravel\Mcp\Server\ServerContext;
use Laravel\Mcp\Transport\JsonRpcRequest;
use Laravel\Mcp\Transport\JsonRpcResponse;
use Throwable;

final class MeasuredCallTool extends CallTool
{
    public function __construct(private LogManager $logs)
    {
    }

    public function handle(JsonRpcRequest $request, ServerContext $context): Generator|JsonRpcResponse
    {
        if (! config('game-mcp.metrics.enabled')) {
            return parent::handle($request, $context);
        }

        $startedAt = hrtime(true);
        $response = null;

        try {
            $response = parent::handle($request, $context);

            if ($response instanceof Generator) {
                return $this->measureStream($response, $request, $context, $startedAt);
            }

            return $response;
        } finally {
            if (! $response instanceof Generator) {
                $this->record($request, $context, $startedAt, $response);
            }
        }
    }

    /**
     * @param Generator<JsonRpcResponse> $responses
     * @return Generator<JsonRpcResponse>
     */
    private function measureStream(Generator $responses, JsonRpcRequest $request, ServerContext $context, int $startedAt): Generator
    {
        $response = null;
        $bytes = 0;
        try {
            foreach ($responses as $item) {
                $response = $item;
                $bytes += strlen($item->toJson());
                yield $item;
            }
        } finally {
            $this->record($request, $context, $startedAt, $response, $bytes);
        }
    }

    private function record(JsonRpcRequest $request, ServerContext $context, int $startedAt, ?JsonRpcResponse $response, ?int $responseBytes = null): void
    {
        $duration = (hrtime(true) - $startedAt) / 1_000_000;

        try {
            $result = $response?->toArray()['result'] ?? [];
            $payload = $result['structuredContent'] ?? [];
            $gameId = $payload['game_id'] ?? $request->get('arguments', [])['game_id'] ?? null;
            $tool = $context->tools()->first(fn ($tool): bool => $tool->name() === $request->get('name'));
            $userId = auth('sanctum')->id() ?? config('game-mcp.user_id');

            $this->logs->channel('mcp')->info('mcp.tool_call', [
                'tool' => $tool?->name(),
                'game_id' => is_int($gameId) && $gameId > 0 ? $gameId : null,
                'user_id' => is_numeric($userId) && (int) $userId > 0 ? (int) $userId : null,
                'duration_ms' => round($duration, 3),
                'input_bytes' => strlen(json_encode($request->get('arguments', (object) []), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
                'response_bytes' => $responseBytes ?? ($response === null ? null : strlen($response->toJson())),
                'status' => $response === null || isset($response->toArray()['error']) || ($result['isError'] ?? false)
                    ? 'error'
                    : (($payload['reason'] ?? null) === 'timeout' ? 'timeout' : 'success'),
            ]);
        } catch (Throwable) {
            // Metrics must never interrupt or cause a retry of a confirmed game action.
        }
    }
}
