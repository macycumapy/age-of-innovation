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

When asked to join, use join_game with the requested game_id. It creates membership in a lobby as the authenticated HTTP user or configured STDIO user, but does not mark readiness or start the game. Missing membership is not permission to choose another party. Join only when authorized. Do not change configuration to control another seat.

Prefer get_turn_context when available to read state and legal actions at one version; otherwise read get_state and get_legal_actions for the same version. Resolve the controlled seat's pending choices before normal actions. If versions differ, refresh. An empty action list does not authorize passing, finishing, or bypassing validation.

## Continue playing and wait for turns

A request to play (such as “Играем”) authorizes continuing play for the configured seat across turns until the user stops or pauses, the game ends, or a decision requires clarification. Joining alone and requests for advice do not authorize autonomous play.

When another player must act during authorized play, briefly report the move and call wait_for_turn with the requested game_id. Use its default timeout. On timeout, call it again while play remains authorized; a timeout does not end the playing session. On your_turn, obtain current legal actions and continue playing. On game_ended, stop and report the result. Keep the user informed during extended waits without ending the response or requiring another “Играем”.

After confirm_action, follow the returned continuation when available: your_turn means refresh turn context; wait_for_turn means wait directly; game_ended means stop. If waiting is unavailable or fails in a way that prevents continuing, explain the limitation and yield. Do not claim that waiting continues after the response ends.

## Choose a move

Maintain a short plan: next scoring opportunity, economic bottleneck, intended expansion or town site, and required resources/actions. After founding a town, reassess where an independent next town could form before continuing construction around the existing one. Reassess after an opponent move or material change.

Apply the concrete checks in the reference's “Strategic decision checks” section before committing to a strategic line. Treat those checks as evaluation criteria, not a fixed opening or a requirement to pursue towns in every position.

Compare immediate points, future income before game end, pass rewards, resource scarcity, knowledge/network ranking swings, and competition for shared actions or building sites. Count the entire sequence, including conversions, terraforming, shipping, and follow-up choices. Prefer an affordable continuation over a plan requiring several unavailable resources.

Bot evaluations can inform a decision but are heuristics, not game rules or proof of optimal play. Do not delegate a requested human-like decision to the automatic turn executor.

## Execute a move

For resource exchanges, calculate the required total first and prefer a single offered action with that amount over repeated one-unit exchanges. Inspect the live schema and legal actions for quantity support; do not assume a UI capability is exposed through MCP. If only one-unit actions are exposed, explain that limitation and use those exact actions rather than inventing an amount or action ID. Reassess between exchanges only when an intervening choice or state change can affect the plan.

1. Select an exact action_id from get_legal_actions. Do not invent IDs or submit arbitrary parameter objects.
2. Call stage_action with game_id, version, and action_id. This records a selection; it does not simulate or spend resources.
3. Call confirm_action only when authorized to play autonomously or after the user approves. Otherwise describe the choice and wait. rollback_action clears an unconfirmed selection; it cannot undo a confirmed action.
4. Follow the confirmation's continuation as described above; if none is provided, refresh the state and legal actions. Resolve mandatory follow-up choices through the same stage/confirm cycle. The offered finish_turn action also requires staging and confirmation.

After a stale-version error, refresh and reassess. After a timeout or ambiguous confirmation response, read the state before retrying: the action may have committed. If MCP cannot express a legal move, report the limitation rather than writing the database or forcing actions through shell commands.

preview_action is not currently exposed. Do not claim to have simulated a line or describe a heuristic estimate as verified. Repository inspection during play is read-only; do not inspect internal snapshots or opponents' stored plans for information beyond the player view.

After each turn, briefly explain the move, its main reason, unresolved choices, and whose turn is next in the user's language. During authorized play, give this update without ending the response, then continue through wait_for_turn.
