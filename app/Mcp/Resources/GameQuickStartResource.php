<?php

declare(strict_types=1);

namespace App\Mcp\Resources;

use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\MimeType;
use Laravel\Mcp\Server\Attributes\Uri;
use Laravel\Mcp\Server\Resource;

#[Uri('game://quick-start')]
#[MimeType('text/markdown')]
#[Description('Short starting rules and MCP gameplay protocol. Read once; load detailed rules and component descriptions only when needed.')]
final class GameQuickStartResource extends Resource
{
    private const string CONTENT = <<<'TEXT'
        # Quick start

        Aim for victory points across six rounds. Build an economy early, align actions with current and upcoming round goals, and compare final network and knowledge rankings. Costs, legal options, mandatory choices, and timing come from the live engine; do not substitute remembered rules from another game.

        Coins and tools fund buildings; books and scholars support research and development. Only power in bowl III is spendable. Upgrading replaces a building's old income. Town connectivity differs from shipping/network connectivity: use actual map geometry and legal town choices, not coordinate guesses. Before passing, check remaining special actions and owned pass bonuses.

        Start with get_turn_context. Use its player_id as your seat and its version/actions together. Resolve mandatory choices first. For authorized autonomous play, select an exact action_id and call execute_action with the current version: it stages and confirms internally. This commits the move, not a preview; do not call confirm_action afterwards. Separate stage_action/confirm_action/rollback_action remain for selection review or approval. Clear an existing staged selection explicitly before execute_action. Rollback does not undo confirmed actions. After either execution or confirmation follow continuation: your_turn -> use context_update and fresh actions directly, wait_for_turn -> wait directly, game_ended -> stop. After stale versions or ambiguous mutation failures, read get_turn_context before retrying; never blindly repeat a possibly committed move.

        Resource exchanges and power sacrifices list every currently affordable amount as a separate action_id. Select the amount needed for your plan and confirm it once instead of repeating one-unit exchanges. Amounts are alternatives, not independent budgets; after any confirmation use the fresh actions.

        If stage_action fails or its result is uncertain, do not call confirm_action. Fetch get_turn_context, choose again from its current actions, and stage again. Confirm only after a successful stage_action response whose staged_action matches the intended action_id and version. Never batch stage_action and confirm_action in parallel or continue to confirmation without inspecting the staging result.

        context_update context-delta-v1 requires a held context with matching game_id and base_version. Apply changes in order using path arrays (literal keys/zero-based indices): set replaces/adds the exact value, including null or whole arrays; remove deletes an object key. Replace actions with context_update.actions and use its new version. context-snapshot-v1 replaces the entire context with context_update.context. If the baseline is lost, mismatched, or uncertain, get_turn_context; never repeat a confirmed action to recover state.

        compact-v2 is a full dynamic snapshot. Fetch get_board_layout only when its layout_id/geometry is unknown or lost; join rows by hex id using each response's field headers. wait_for_turn your_turn includes state, actions, and their shared version: choose directly without another state/context/actions read. On timeout, continue only while authorized. A finished chat cannot promise automatic resumption.
        Save context_id with each full context or applied context_update. Pass it to wait_for_turn only if you still hold that exact context. Wait can return a smaller context_update: check base_context_id, game_id, and base_version, apply changes, and replace actions. Missing/expired/foreign snapshots return a full context. Replace context_id with the new ID; discard it if absent. Timeout preserves context/ID. Confirmation without context_update preserves the previous snapshot/ID too: do not advance that snapshot's version from the confirmation summary. If context is lost, omit context_id for a full response.

        Use get_component(type,id) only for an unfamiliar component relevant to a decision; reuse descriptions already read. Types: faction, competency, innovation, palace, round_bonus, town_tile. Descriptions are engine summaries, not proof of availability or a complete explanation of interactions. For an unresolved rule consult game://rules; game://components and game://play-guide remain optional full references. Do not preload all references or look up every component automatically.
        TEXT;

    public function handle(): Response
    {
        return Response::text(self::CONTENT);
    }
}
