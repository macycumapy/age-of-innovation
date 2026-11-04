<?php

declare(strict_types=1);

use App\Http\Middleware\HandleAsyncMcpProbe;
use App\Mcp\Server\GameServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Sanctum\Http\Middleware\CheckAbilities;

Mcp::local('game', GameServer::class);

Route::middleware([HandleAsyncMcpProbe::class, 'auth:sanctum', CheckAbilities::class.':game:mcp'])->group(function (): void {
    Mcp::web('/mcp/game', GameServer::class)->name('mcp.game');
});
