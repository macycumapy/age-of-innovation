<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\PlayGamePrompt;
use App\Mcp\Resources\GameComponentsResource;
use App\Mcp\Resources\GamePlayGuideResource;
use App\Mcp\Resources\GameRulesResource;
use App\Mcp\Tools\ConfirmGameActionTool;
use App\Mcp\Tools\GetGameStateTool;
use App\Mcp\Tools\GetLegalGameActionsTool;
use App\Mcp\Tools\JoinGameTool;
use App\Mcp\Tools\RollbackGameActionTool;
use App\Mcp\Tools\StageGameActionTool;
use App\Mcp\Tools\WaitForGameTurnTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Game Server')]
#[Version('0.0.1')]
class GameServer extends Server
{
    protected string $instructions = <<<'TEXT'
        Before your first game, read game://rules, game://components, and game://play-guide using resources/read.
        Use join_game with game_id to join an open lobby and automatically mark yourself ready. Existing lobby members are marked ready too; the game is not started.
        HTTP uses the authenticated token owner; local STDIO uses GAME_MCP_USER_ID.
        Read state and legal actions.
        While authorized to keep playing, use wait_for_turn when waiting for your turn. On timeout, call it again; on game_ended, stop. On your_turn, read legal actions and use the returned current version.
        Stage an action, then explicitly confirm or roll back.
        Use the current version for action mutations.
        Resolve pending choices before finishing the turn.
        Access is limited to the configured user.
        If the user participates in multiple games, specify game_id on each call.
        TEXT;

    protected array $tools = [
        JoinGameTool::class,
        GetGameStateTool::class,
        WaitForGameTurnTool::class,
        GetLegalGameActionsTool::class,
        StageGameActionTool::class,
        ConfirmGameActionTool::class,
        RollbackGameActionTool::class,
    ];

    protected array $resources = [
        GameRulesResource::class,
        GameComponentsResource::class,
        GamePlayGuideResource::class,
    ];

    protected array $prompts = [
        PlayGamePrompt::class,
    ];
}
