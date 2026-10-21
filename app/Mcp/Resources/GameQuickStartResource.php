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

        Start with get_turn_context. Use its player_id as your seat and its version/actions together. Resolve mandatory choices first. Select an exact action_id, stage_action, then explicitly confirm_action or rollback_action. Staging spends nothing; rollback does not undo confirmed actions. After confirmation follow continuation: your_turn -> use context_update and fresh actions directly, wait_for_turn -> wait directly, game_ended -> stop. Refresh context after stale versions or ambiguous failures before retrying.

        context_update context-delta-v1 requires a held context with matching game_id and base_version. Apply changes in order using path arrays (literal keys/zero-based indices): set replaces/adds the exact value, including null or whole arrays; remove deletes an object key. Replace actions with context_update.actions and use its new version. context-snapshot-v1 replaces the entire context with context_update.context. If the baseline is lost, mismatched, or uncertain, get_turn_context; never repeat a confirmed action to recover state.

        compact-v2 is a full dynamic snapshot. Fetch get_board_layout only when its layout_id/geometry is unknown or lost; join rows by hex id using each response's field headers. wait_for_turn your_turn includes state, actions, and their shared version: choose directly without another state/context/actions read. On timeout, continue only while authorized. A finished chat cannot promise automatic resumption.

        Use get_component(type,id) only for an unfamiliar component relevant to a decision; reuse descriptions already read. Types: faction, competency, innovation, palace, round_bonus, town_tile. Descriptions are engine summaries, not proof of availability or a complete explanation of interactions. For an unresolved rule consult game://rules; game://components and game://play-guide remain optional full references. Do not preload all references or look up every component automatically.
        TEXT;

    public function handle(): Response
    {
        return Response::text(self::CONTENT);
    }
}
