<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Mcp\Server\AsyncMcpProbe;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

final class HandleAsyncMcpProbe
{
    public function __construct(private AsyncMcpProbe $probes, private ThrottleRequests $throttle)
    {
    }

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Context::forgetHidden(AsyncMcpProbe::CONTEXT);
        Context::forgetHidden(AsyncMcpProbe::GAME_CONTEXT);
        $mode = $this->probes->verify($request);
        abort_if($request->hasHeader(AsyncMcpProbe::HEADER) && $mode === null, 403);
        if ($mode !== null) {
            Context::addHidden(AsyncMcpProbe::CONTEXT, $mode);
        }

        try {
            $response = $mode === 'refresh' ? $next($request) : $this->throttle->handle($request, $next, 120, 1);
            $gameId = Context::getHidden(AsyncMcpProbe::GAME_CONTEXT);
            if ($mode !== null && is_int($gameId) && $gameId > 0) {
                $response->headers->set(AsyncMcpProbe::GAME_HEADER, (string) $gameId);
            }

            return $response;
        } finally {
            Context::forgetHidden(AsyncMcpProbe::CONTEXT);
            Context::forgetHidden(AsyncMcpProbe::GAME_CONTEXT);
        }
    }
}
