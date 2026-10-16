---
name: age-of-innovation-player
description: Play Age of Innovation in this project through game MCP as an opponent or adviser. Use for playing a party, choosing moves, planning expansion, or explaining a position. Do not use for implementing or debugging the application.
---

# Age of Innovation player

Play the project's implemented rules as the configured user. Respect the requested role: opponent, adviser, or observer. Advice does not authorize executing moves.

Read [rules-and-strategy.md](references/rules-and-strategy.md) at the start of a game. Consult [components.md](references/components.md) for the IDs present in the position. Source paths in references are relative to the project root; consult current implementations when exceptions matter.

## Establish the position

Discover the live MCP schema. The server exposes six tools: join_game, get_state, get_legal_actions, stage_action, confirm_action, and rollback_action. Call the appropriate tool directly without an operation argument. Connector prefixes can change the exposed tool name.

Identify game_id, user_id, player_id, active_player_id, version, round, phase, and pending interaction. HTTP identifies the user by the Bearer token; local STDIO uses GAME_MCP_USER_ID. Board ownership and turn order use game-player IDs. Always pin the requested game_id on every call.

When asked to join, use join_game with the requested game_id. It creates membership in a lobby as the authenticated HTTP user or configured STDIO user, but does not mark readiness or start the game. Missing membership is not permission to choose another party. Join only when authorized. Do not change configuration to control another seat.

Read get_state and get_legal_actions for the same version. Resolve the controlled seat's pending choices before normal actions. If versions differ, refresh. When another player must act, report that and yield. An empty action list does not authorize passing, finishing, or bypassing validation.

## Choose a move

Maintain a short plan: next scoring opportunity, economic bottleneck, intended expansion or town site, and required resources/actions. Reassess after an opponent move or material change.

Apply the concrete checks in the reference's “Strategic decision checks” section before committing to a strategic line. Treat those checks as evaluation criteria, not a fixed opening or a requirement to pursue towns in every position.

Compare immediate points, future income before game end, pass rewards, resource scarcity, knowledge/network ranking swings, and competition for shared actions or building sites. Count the entire sequence, including conversions, terraforming, shipping, and follow-up choices. Prefer an affordable continuation over a plan requiring several unavailable resources.

Bot evaluations can inform a decision but are heuristics, not game rules or proof of optimal play. Do not delegate a requested human-like decision to the automatic turn executor.

## Execute a move

1. Select an exact action_id from get_legal_actions. Do not invent IDs or submit arbitrary parameter objects.
2. Call stage_action with game_id, version, and action_id. This records a selection; it does not simulate or spend resources.
3. Call confirm_action only when authorized to play autonomously or after the user approves. Otherwise describe the choice and wait. rollback_action clears an unconfirmed selection; it cannot undo a confirmed action.
4. Read state again. Resolve mandatory follow-up choices through the same stage/confirm cycle. The offered finish_turn action also requires staging and confirmation.

After a stale-version error, refresh and reassess. After a timeout or ambiguous confirmation response, read the state before retrying: the action may have committed. If MCP cannot express a legal move, report the limitation rather than writing the database or forcing actions through shell commands.

preview_action is not currently exposed. Do not claim to have simulated a line or describe a heuristic estimate as verified. Repository inspection during play is read-only; do not inspect internal snapshots or opponents' stored plans for information beyond the player view.

After each turn, briefly explain the move, its main reason, unresolved choices, and whose turn is next in the user's language. When waiting, yield to the user; do not claim a background game loop is running.
