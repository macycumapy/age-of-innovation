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

Prefer get_turn_context when available to read state and legal actions at one version; otherwise read get_state and get_legal_actions for the same version. Resolve the controlled seat's pending choices before normal actions. If versions differ, refresh. An empty action list does not authorize passing, finishing, or bypassing validation.

## Continue playing and wait for turns

A request to play (such as “Играем”) authorizes continuing play for the configured seat across turns until the user stops or pauses, the game ends, or a decision requires clarification. Joining alone and requests for advice do not authorize autonomous play.

When another player must act during authorized play, briefly report the move and call wait_for_turn with the requested game_id. Use its default timeout. On timeout, call it again while play remains authorized; a timeout does not end the playing session. On your_turn, obtain current legal actions and continue playing. On game_ended, stop and report the result. Keep the user informed during extended waits without ending the response or requiring another “Играем”.

After confirm_action, follow the returned continuation: your_turn means apply context_update to the exact held baseline and use its fresh actions; wait_for_turn means wait directly; game_ended means stop. Keep context_id with its snapshot and pass it to wait_for_turn only while that exact context is held. Replace full snapshots; apply deltas only when their game, base version, and supplied base context ID match. Refresh turn context if the baseline is missing or uncertain. If waiting is unavailable or fails in a way that prevents continuing, explain the limitation and yield. Do not claim that waiting continues after the response ends.

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

1. Select an exact action_id from get_legal_actions. Complete any applicable decision checkpoint before staging it. Do not invent IDs or submit arbitrary parameter objects.
2. Call stage_action with game_id, version, and action_id. This records a selection; it does not simulate or spend resources.
3. Call confirm_action only when authorized to play autonomously or after the user approves. Otherwise describe the choice and wait. rollback_action clears an unconfirmed selection; it cannot undo a confirmed action.
4. Follow the confirmation's continuation as described above; if none is provided, refresh the state and legal actions. Resolve mandatory follow-up choices through the same stage/confirm cycle. The offered finish_turn action also requires staging and confirmation.

After a stale-version error, refresh and reassess. After a timeout or ambiguous confirmation response, read the state before retrying: the action may have committed. If MCP cannot express a legal move, report the limitation rather than writing the database or forcing actions through shell commands.

preview_action is not currently exposed. Do not claim to have simulated a line or describe a heuristic estimate as verified. Repository inspection during play is read-only; do not inspect internal snapshots or opponents' stored plans for information beyond the player view.

After each turn, briefly explain the move, its main reason, unresolved choices, and whose turn is next in the user's language. During authorized play, give this update without ending the response, then continue through wait_for_turn.
