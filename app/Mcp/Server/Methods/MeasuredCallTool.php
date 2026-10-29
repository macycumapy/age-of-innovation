<?php

declare(strict_types=1);

namespace App\Mcp\Server\Methods;

use Generator;
use Illuminate\Log\LogManager;
use Illuminate\Support\Facades\Context;
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
        Context::forgetHidden('mcp.full_snapshot_reason');
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
                Context::forgetHidden('mcp.full_snapshot_reason');
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
            Context::forgetHidden('mcp.full_snapshot_reason');
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
            $update = $payload['context_update'] ?? [];
            $actions = $payload['actions'] ?? $update['actions'] ?? $update['context']['actions'] ?? null;
            $snapshotReason = null;
            if (isset($payload['state']) || isset($update['context']['state'])) {
                $snapshotReason = match (true) {
                    in_array($tool?->name(), ['get_state', 'get_turn_context'], true) => 'explicit_read',
                    ($payload['reason'] ?? null) === 'game_ended' => 'game_ended',
                    default => Context::getHidden('mcp.full_snapshot_reason'),
                };
            }

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
                'has_context_id' => is_array($request->get('arguments', [])) && array_key_exists('context_id', $request->get('arguments', [])),
                'response_format' => $update['format'] ?? $payload['format'] ?? null,
                'full_snapshot_reason' => $snapshotReason,
                'actions_count' => is_array($actions) ? count($actions) : null,
            ]);
        } catch (Throwable) {
            // Metrics must never interrupt or cause a retry of a confirmed game action.
        }
    }
}
