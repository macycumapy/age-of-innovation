---
name: age-of-innovation-player
description: Play Age of Innovation in this project through game MCP as an opponent or adviser. Use for playing a party, choosing moves, planning expansion, or explaining a position. Do not use for implementing or debugging the application.
---

# Age of Innovation player

Play the project's implemented rules as the configured user. Respect the requested role: opponent, adviser, or observer. Advice does not authorize executing moves.

Read [rules-and-strategy.md](references/rules-and-strategy.md) at the start of a game. Consult [components.md](references/components.md) for the IDs present in the position. Source paths in references are relative to the project root; consult current implementations when exceptions matter.

## Establish the position

Discover the live MCP schema, including the available turn-context and wait_for_turn tools. Call the appropriate tool directly without an operation argument. Connector prefixes can change the exposed tool name.

Identify game_id, user_id, player_id, active_player_id, version, round, phase, and pending interaction. HTTP identifies the user by the Bearer token; local STDIO uses GAME_MCP_USER_ID. Board ownership and turn order use game-player IDs. Always pin the requested game_id on every call.

When asked to join, use join_game with the requested game_id. It creates membership as the configured user and, under the current live schema, marks that player ready without starting the game. Check the response for readiness. Missing membership is not permission to choose another party. Join only when authorized. Do not change configuration to control another seat.

Start with get_turn_context to obtain state and legal actions at one shared version. If unavailable, read get_state and get_legal_actions for the same version; refresh if those separately read versions disagree. Afterwards reuse the context and fresh actions returned by wait_for_turn or confirm_action.context_update instead of repeating reads. Resolve the controlled seat's pending choices before normal actions. An empty action list does not authorize passing, finishing, or bypassing validation.

## Continue playing and wait for turns

A request to play (such as “Играем”) authorizes continuing play for the configured seat across turns until the user stops or pauses, the game ends, or a decision requires clarification. Joining alone and requests for advice do not authorize autonomous play.

When another player must act during authorized play, briefly report the move and call wait_for_turn with the requested game_id and the context_id of the exact snapshot still held. Use its default timeout. On reason=timeout, repeat the wait while play remains authorized, preserving the context and its ID; do not fetch state or actions because of this normal timeout. On your_turn, apply the returned update or replace the full context and choose directly from its actions. On game_ended, stop and report the result. Keep the user informed during extended waits without ending the response or requiring another “Играем”.

After confirm_action, follow continuation: your_turn means apply context_update and use its fresh actions; wait_for_turn means wait directly; game_ended means stop. If no context_update is returned, retain the previous snapshot and context_id unchanged: the confirmation's new version and action summary do not update that snapshot. Never assign the confirmation version to an older snapshot or infer a state delta from the action summary. If waiting is unavailable or fails in a way that prevents continuing, explain the limitation and yield. Do not claim that waiting continues after the response ends.

Keep context_id paired with the exact held context, not just its version. A full compact-v2 response or context-snapshot-v1.context replaces the whole context; after applying any update/full context, save its returned context_id, or discard the old ID if none is returned. Apply context-delta-v1 only when game_id and base_version match the held context and any supplied base_context_id matches its ID. Apply changes in order using literal path keys/zero-based indices: set replaces/adds the exact value (including null or a whole array), remove deletes an object key. Replace actions with the returned list. Version jumps are normal after other players act; a matching delta does not require consecutive versions or another read.

Recover with get_turn_context when the baseline is actually missing, required fields cannot be reconstructed, or delta identifiers do not match. If already waiting with a lost baseline, omit context_id to obtain a full response on your_turn. Strategic uncertainty alone is not a reason to discard a valid snapshot. Round changes automatically return full context: replace it and reassess the position without fetching another snapshot. Fetch get_board_layout only when geometry for state.board.layout_id is missing, not on every full context or round change; join geometry and dynamic rows by hex ID.

When the other players have passed, consecutive confirmed turns can return your_turn for the controlled seat. Continue from those fresh actions without waiting for an opponent. Keep explanations brief and execute the chosen line; do not repeatedly announce an imminent move without staging it.

If the game returns to an earlier version or a previously confirmed position changes, discard the old action sequence and reassess from the returned full context. Fetch a full context if only an incompatible delta is available. Do not repeat old confirmations or infer why the position changed; describe the observed change neutrally. A stale staged_action is not authority to confirm it: stage a currently offered action successfully first.

## Choose a move

Keep a compact decision record in the conversation's working context, alongside the held game context. Record the game/version, next scoring opportunity, economic bottleneck, intended expansion or town site, and required resources/actions. Update it instead of reconstructing the plan from earlier commentary. After founding a town, reassess where an independent next town could form before continuing construction around the existing one.

Apply the concrete checks in the reference's “Strategic decision checks” section before committing to a strategic line. Treat those checks as evaluation criteria, not a fixed opening or a requirement to pursue towns in every position.

Compare immediate points, future income before game end, pass rewards, resource scarcity, knowledge/network ranking swings, and competition for shared actions or building sites. Count the entire sequence, including conversions, terraforming, shipping, and follow-up choices. Prefer an affordable continuation over a plan requiring several unavailable resources.

### Decision checkpoints

Before the first discretionary main action of each round, record a numerical final-score range for every player: current points + remaining repeatable rewards + affordable construction/towns/innovations + knowledge/network awards + resource conversion. Name the likely leader, the largest uncertainty, and what your intended line must achieve to close any deficit. Include opponents' construction bonuses and affordable replies, not only their current pass payout. Use estimates for uncertain continuations; do not delay play seeking an exact prediction.

Before staging a strategic commitment (setup bundle, major upgrade, competency, innovation, development, paid power offer, or funding conversion for a multi-action plan), compare the intended line with at least one feasible alternative from the current position. Passing or keeping flexible resources can be the alternative. Record each line's full remaining cost, required turns, scoring package, and useful continuation after payment. Apply the relevant checks from the reference to those concrete numbers. An affordable next action or a desirable component is not sufficient justification for the whole line.

Record why the selected line wins and what would invalidate it. Recheck that reason after an opponent blocks a site, takes a shared action, changes a ranking threshold, or acquires a major scoring ability. Update the affected score ranges and compare the remaining plan again before spending more. Treat earlier spending as sunk cost. If the old line no longer wins, replace it; do not fund successive substitutions merely to preserve the announced plan.

After confirmation, compare the actual reward, resources, and attainable continuation with the record. Correct a mismatch before the next discretionary action. Mandatory follow-ups, finish_turn, and successive quantity-limited exchanges can reuse the checked line while its assumptions still hold; they do not require another full comparison. These checkpoints are part of choosing a move, not a request for user approval, an extra game action, or permission to reread an already-held snapshot.

At round transitions, briefly tell the user the projected leader or deficit and the resulting priority. For a strategic commitment, briefly give the decisive comparison with the alternative, using numbers where they determine the choice. Distinguish confirmed outcomes from estimates. Do not claim that a move keeps pace or secures victory without an updated forecast supporting it. Keep these updates concise and continue playing.

Bot evaluations can inform a decision but are heuristics, not game rules or proof of optimal play. Do not delegate a requested human-like decision to the automatic turn executor.

## Execute a move

For resource exchanges, calculate the required total first and prefer a single offered action with that amount over repeated one-unit exchanges. Inspect the live schema and legal actions for quantity support; do not assume a UI capability is exposed through MCP. If only one-unit actions are exposed, explain that limitation and use those exact actions rather than inventing an amount or action ID. Reassess between exchanges only when an intervening choice or state change can affect the plan.

1. Select an exact action_id from the fresh actions in the held context, wait result, or confirmation update. Use get_legal_actions only when actions alone are missing, not before every move. Complete any applicable decision checkpoint before staging it. Do not invent IDs or submit arbitrary parameter objects.
2. Call stage_action with game_id, version, and action_id. This records a selection; it does not simulate or spend resources. Inspect the successful staged_action and verify its action_id and version before confirming. If staging fails or its result is uncertain, do not confirm; recover the context, choose again, and stage successfully. Never run staging and confirmation in parallel.
3. Call confirm_action only when authorized to play autonomously or after the user approves. Otherwise describe the choice and wait. rollback_action clears an unconfirmed selection; it cannot undo a confirmed action.
4. Follow the confirmation's continuation and context update as described above; if continuation is absent, recover with get_turn_context. Resolve mandatory follow-up choices through the same stage/confirm cycle using fresh returned actions. The offered finish_turn action also requires staging and confirmation.

After a stale-version error, get_turn_context and reassess. After a transport timeout or ambiguous result of confirm_action, read get_turn_context before retrying: the action may have committed, so never blindly repeat it. This is different from wait_for_turn returning reason=timeout, which requires only another authorized wait with the same held context/ID. If MCP cannot express a legal move, report the limitation rather than writing the database or forcing actions through shell commands.

preview_action is not currently exposed. Do not claim to have simulated a line or describe a heuristic estimate as verified. Repository inspection during play is read-only; do not inspect internal snapshots or opponents' stored plans for information beyond the player view.

After each turn, briefly explain the move, its main reason, unresolved choices, and whose turn is next in the user's language. During authorized play, give this update without ending the response, then continue through wait_for_turn.
