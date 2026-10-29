<?php

declare(strict_types=1);

namespace App\Mcp\Server;

use App\Mcp\Prompts\PlayGamePrompt;
use App\Mcp\Resources\GameComponentsResource;
use App\Mcp\Resources\GamePlayGuideResource;
use App\Mcp\Resources\GameQuickStartResource;
use App\Mcp\Resources\GameRulesResource;
use App\Mcp\Server\Methods\MeasuredCallTool;
use App\Mcp\Tools\ExecuteGameActionTool;
use App\Mcp\Tools\GetGameBoardLayoutTool;
use App\Mcp\Tools\GetGameComponentTool;
use App\Mcp\Tools\GetGameStateTool;
use App\Mcp\Tools\GetGameTurnContextTool;
use App\Mcp\Tools\GetLegalGameActionsTool;
use App\Mcp\Tools\JoinGameTool;
use App\Mcp\Tools\WaitForGameTurnTool;
use Laravel\Mcp\Server;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Attributes\Version;

#[Name('Game Server')]
#[Version('0.0.8')]
class GameServer extends Server
{
    protected function boot(): void
    {
        parent::boot();

        $this->addMethod('tools/call', MeasuredCallTool::class);
    }

    protected string $instructions = <<<'TEXT'
        Before your first game, read only game://quick-start using resources/read. Fetch get_component(type,id) only for unfamiliar components relevant to a decision and reuse known descriptions. Full references game://rules, game://components, and game://play-guide are optional: read only when needed, not automatically at startup.
        Use join_game with game_id to join an open lobby and automatically mark yourself ready. Existing lobby members are marked ready too; the game is not started.
        HTTP uses the authenticated token owner; local STDIO uses GAME_MCP_USER_ID.
        Prefer get_turn_context to read compact state and legal actions together with one shared version. Use get_state or get_legal_actions only when that information alone is needed; do not immediately repeat reads already covered by the context.
        State format compact-v2 is a complete dynamic snapshot, not a delta. Static geometry is separate: fetch get_board_layout for the current game when state.board.layout_id is unknown or its geometry is missing from your context. Reuse geometry by layout_id, not just map variant. If layout IDs disagree, refresh the turn context before deciding. Join layout and state hexes by id, never by row position.
        Board hexes, buildings, and bridges are positional rows: use each response's hex_fields, building_fields, and bridge_fields. Missing trailing dynamic hex values are null. Current terrain, ownership, towns, neutral buildings, annexes, and bridges are always included. Setup-only selections are omitted after setup; scoring goals and shared actions remain available.
        Action IDs are opaque and version-scoped; always refresh legal actions after a version change. finish_turn is the only fixed ID and still requires the current version.
        While authorized to keep playing, use wait_for_turn without timeout_seconds to wait up to 30 seconds on each call. On timeout, call it again; on game_ended, stop. On your_turn, use the returned state, actions, and shared version directly; do not call get_state, get_turn_context, or get_legal_actions again for the same version.
        Keep context_id alongside the exact context returned by get_turn_context, successful wait_for_turn, or execute_action.context_update. Pass it to wait_for_turn only while that context is still held. A wait delta requires matching base_context_id, game_id, and base_version; otherwise fetch get_turn_context. Unknown/expired/foreign IDs return a full context. Replace the held context_id with the new one after applying an update/full context; discard it if absent. Timeout leaves context and its ID unchanged. A confirmation without context_update also leaves the held snapshot/ID unchanged; do not advance its version from the confirmation summary alone. If context is lost or uncertain, omit context_id and request a full context. Use reconstructed state and fresh actions directly.
        Use execute_action(game_id, version, action_id) to commit a chosen legal action in one call. Staging and confirmation run internally; the call itself is the explicit commitment, not a preview. Never blindly repeat an ambiguous execution: get_turn_context first. If an existing staged selection blocks execution, ask the user to resolve it outside MCP; do not silently overwrite it.
        A round change forces a full context instead of a delta. Replace the previous context, review the new round goals, economy, board, and opponents, then choose from the returned actions. Do not reread the context just to start the round.
        After execute_action, follow continuation: your_turn -> use context_update and its fresh actions directly; wait_for_turn -> wait_for_turn without a redundant state/context read; game_ended -> stop. next_player_id identifies a game player, not a user. The action summary is not a state delta.
        context_update format context-delta-v1 applies only to your held compact-v2 context with matching game_id and base_version. Apply changes in order: path is an array of literal object keys or zero-based array indices; set replaces/adds exactly that value (including null or an entire array), remove deletes that object key. Never merge arrays or infer resource changes from the action summary. Replace actions with context_update.actions and use its new version. For context-snapshot-v1 replace the entire context with context_update.context. If your baseline is missing, mismatched, or uncertain, fetch get_turn_context before acting. Do not replay the confirmed action to recover context.
        Use the current version for action mutations.
        Resolve pending choices before finishing the turn.
        Access is limited to the configured user.
        If the user participates in multiple games, specify game_id on each call.
        TEXT;

    protected array $tools = [
        JoinGameTool::class,
        GetGameStateTool::class,
        GetGameTurnContextTool::class,
        GetGameBoardLayoutTool::class,
        GetGameComponentTool::class,
        WaitForGameTurnTool::class,
        GetLegalGameActionsTool::class,
        ExecuteGameActionTool::class,
    ];

    protected array $resources = [
        GameRulesResource::class,
        GameComponentsResource::class,
        GamePlayGuideResource::class,
        GameQuickStartResource::class,
    ];

    protected array $prompts = [
        PlayGamePrompt::class,
    ];
}
