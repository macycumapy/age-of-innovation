<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use Illuminate\Http\Request;
use LogicException;

final class AsyncMcpProbe
{
    public const string HEADER = 'X-Mcp-Async-Probe';

    public const string GAME_HEADER = 'X-Mcp-Wait-Game';

    public const string CONTEXT = 'mcp.async_probe';

    public const string GAME_CONTEXT = 'mcp.async_game_id';

    public function sign(string $body, string $authorization, string $cookie, string $mode): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            throw new LogicException('Configure APP_KEY before starting the MCP gateway.');
        }

        return $mode.':'.hash_hmac('sha256', "mcp-read-only-probe\0{$mode}\0{$authorization}\0{$cookie}\0{$body}", $key);
    }

    public function verify(Request $request): ?string
    {
        $header = (string) $request->header(self::HEADER);
        $mode = explode(':', $header, 2)[0];
        if (! in_array($mode, ['initial', 'refresh'], true)
            || $request->input('method') !== 'tools/call' || $request->input('params.name') !== 'wait_for_turn') {
            return null;
        }

        return hash_equals($this->sign($request->getContent(), (string) $request->header('Authorization'), (string) $request->header('Cookie'), $mode), $header)
            ? $mode : null;
    }
}
