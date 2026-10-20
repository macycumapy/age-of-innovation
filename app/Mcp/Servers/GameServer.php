<?php

declare(strict_types=1);

namespace App\Mcp\Servers;

use App\Mcp\Prompts\PlayGamePrompt;
use App\Mcp\Resources\GameComponentsResource;
use App\Mcp\Resources\GamePlayGuideResource;
use App\Mcp\Resources\GameRulesResource;
use App\Mcp\Tools\ConfirmGameActionTool;
use App\Mcp\Tools\GetGameStateTool;
use App\Mcp\Tools\GetGameTurnContextTool;
use App\Mcp\Tools\GetLegalGameActionsTool;
use App\Mcp\Tools\JoinGameTool;
use App\Mcp\Tools\RollbackGameActionTool;
use App\Mcp\Tools\StageGameActionTool;
use App\Mcp\Tools\WaitForGameTurnTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Game Server')]
#[Version('0.0.2')]
class GameServer extends Server
{
    protected string $instructions = <<<'TEXT'
        Before your first game, read game://rules, game://components, and game://play-guide using resources/read.
        Use join_game with game_id to join an open lobby and automatically mark yourself ready. Existing lobby members are marked ready too; the game is not started.
        HTTP uses the authenticated token owner; local STDIO uses GAME_MCP_USER_ID.
        Prefer get_turn_context to read compact state and legal actions together with one shared version. Use get_state or get_legal_actions only when that information alone is needed; do not immediately repeat reads already covered by the context.
        State format compact-v1 is a complete snapshot, not a delta. Board hexes, buildings, and bridges are positional rows: use hex_fields, building_fields, and bridge_fields to decode them. Missing trailing hex values are null. All geometry, terrain, ownership, towns, neutral buildings, and annexes remain included. Setup-only selections are omitted after setup; scoring goals and shared actions remain available.
        Action IDs are opaque and version-scoped; always refresh legal actions after a version change. finish_turn is the only fixed ID and still requires the current version.
        While authorized to keep playing, use wait_for_turn without timeout_seconds to wait up to 30 seconds on each call. On timeout, call it again; on game_ended, stop. On your_turn, read legal actions and use the returned current version.
        Stage an action, then explicitly confirm or roll back.
        After confirm_action, follow continuation: your_turn -> get_turn_context; wait_for_turn -> wait_for_turn without a redundant state/context read; game_ended -> stop. next_player_id identifies a game player, not a user. The action summary is not a state delta.
        Use the current version for action mutations.
        Resolve pending choices before finishing the turn.
        Access is limited to the configured user.
        If the user participates in multiple games, specify game_id on each call.
        TEXT;

    protected array $tools = [
        JoinGameTool::class,
        GetGameStateTool::class,
        GetGameTurnContextTool::class,
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
