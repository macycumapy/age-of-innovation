<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Tools\GameSessionTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Game Server')]
#[Version('0.0.1')]
class GameServer extends Server
{
    protected string $instructions = <<<'TEXT'
        Use join_game with game_id to join an open lobby.
        HTTP uses the authenticated token owner; local STDIO uses GAME_MCP_USER_ID.
        Read state and legal actions.
        Stage an action, then explicitly confirm or roll back.
        Use the current version for action mutations.
        Resolve pending choices before finishing the turn.
        Access is limited to the configured user.
        If the user participates in multiple games, specify game_id on each call.
        TEXT;

    protected array $tools = [
        GameSessionTool::class,
    ];

    protected array $resources = [
        //
    ];

    protected array $prompts = [
        //
    ];
}
