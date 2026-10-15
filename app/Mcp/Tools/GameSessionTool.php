<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Domain\Game\Actions\JoinGameAction;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Interactions\Actions\PerformGameActionOptionAction;
use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
use App\Domain\GameEngine\Turns\Actions\FinishActionTurnAction;
use App\Events\GameChanged;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use DomainException;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\JsonSchema\Types\Type;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Play as the authenticated HTTP token owner or the configured local STDIO user: join_game, get_state, get_legal_actions, stage_action, confirm_action, rollback_action. join_game requires game_id and joins an open lobby. Specify game_id if the user participates in multiple games. Action mutations require version. Staging requires action_id from legal actions and does not change the game. Read state after confirmation to resolve subsequent choices.')]
final class GameSessionTool extends Tool
{
    /**
     * Handle the tool request.
     */
    public function handle(Request $request, GameActionOptionFinder $finder, PerformGameActionOptionAction $perform, FinishActionTurnAction $finish, JoinGameAction $joinGame): Response
    {
        $data = $request->validate([
            'operation' => ['required', 'in:join_game,get_state,get_legal_actions,stage_action,confirm_action,rollback_action'],
            'version' => ['required_if:operation,stage_action,confirm_action,rollback_action', 'integer', 'min:0'],
            'action_id' => ['required_if:operation,stage_action', 'string', 'max:64'],
            'game_id' => ['required_if:operation,join_game', 'integer', 'min:1'],
        ]);

        try {
            $user = $request->user('sanctum');
            if (! $user instanceof User) {
                $userId = (int) config('game-mcp.user_id');
                if ($userId < 1) {
                    throw new DomainException('Configure GAME_MCP_USER_ID first.');
                }
                $user = User::query()->findOrFail($userId);
            }
            if ($data['operation'] === 'join_game') {
                $game = Game::query()->whereKey($data['game_id'])->firstOrFail();
                $player = $joinGame->execute($game, $user);
                GameChanged::dispatch($game->id);

                return Response::json([
                    'joined' => true,
                    'game_id' => $game->id,
                    'user_id' => $user->id,
                    'player_id' => $player->id,
                    'seat' => $player->seat,
                ]);
            }
            $players = $user->gamePlayers()
                ->when(isset($data['game_id']), fn ($query) => $query->where('game_id', $data['game_id']))
                ->limit(2)
                ->get();
            if ($players->isEmpty()) {
                throw new DomainException('The configured user does not participate in this game or has not joined any game yet.');
            }
            if ($players->count() > 1) {
                throw new DomainException('The configured user participates in multiple games. Specify game_id.');
            }
            $player = $players->firstOrFail();

            return DB::transaction(function () use ($data, $player, $finder, $perform, $finish): Response {
                $game = Game::query()->lockForUpdate()->findOrFail($player->game_id);
                $operation = $data['operation'];
                $key = 'game-mcp:staged:'.$player->id;
                if (isset($data['version']) && $game->version !== (int) $data['version']) {
                    throw new DomainException('Game version changed. Read state and select again.');
                }
                if ($operation === 'get_state') {
                    $state = $game->state->toArray();
                    unset($state['turnStartSnapshot'], $state['townChoiceCheckpoint']);
                    foreach ($state['players'] as &$playerState) {
                        unset($playerState['strategicPlan']);
                    }
                    unset($playerState);

                    return Response::json([
                        'game_id' => $game->id, 'user_id' => $player->user_id, 'player_id' => $player->id,
                        'version' => $game->version, 'active_player_id' => $game->active_game_player_id,
                        'state' => $state, 'staged_action' => Cache::get($key),
                    ]);
                }
                if ($operation === 'rollback_action') {
                    Cache::forget($key);

                    return Response::json(['version' => $game->version, 'rolled_back' => true]);
                }
                $options = $this->options($game, $player, $finder);
                if ($operation === 'get_legal_actions') {
                    return Response::json(['version' => $game->version, 'actions' => array_map(
                        static fn (?GameActionOption $option, string $id): array => [
                            'action_id' => $id, 'type' => $option?->type()->value ?? 'finish_turn',
                            'parameters' => $option?->toArray() ?? [],
                        ],
                        array_values($options),
                        array_keys($options),
                    )]);
                }
                if ($operation === 'stage_action') {
                    if (! array_key_exists($data['action_id'], $options)) {
                        throw new DomainException('Select an action from get_legal_actions.');
                    }
                    $staged = ['version' => $game->version, 'action_id' => $data['action_id']];
                    Cache::put($key, $staged, now()->addHour());

                    return Response::json(['staged_action' => $staged]);
                }
                $staged = Cache::get($key);
                if (! is_array($staged) || $staged['version'] !== $game->version || ! array_key_exists($staged['action_id'], $options)) {
                    throw new DomainException('No valid staged action. Stage an action first.');
                }
                $option = $options[$staged['action_id']];
                $game = $option === null ? $finish->execute($game, $player) : $perform->execute($game, $player, $option);
                Cache::forget($key);

                DB::afterCommit(static fn () => GameChanged::dispatch($game->id));

                return Response::json(['version' => $game->version, 'confirmed' => true]);
            });
        } catch (DomainException|ModelNotFoundException|ValidationException $exception) {
            return Response::error($exception->getMessage());
        }
    }

    /** @return array<string, GameActionOption|null> */
    private function options(Game $game, GamePlayer $player, GameActionOptionFinder $finder): array
    {
        if (! $game->isActivePlayer($player)) {
            return [];
        }
        $options = [];
        foreach ($finder->execute($game->state, $player->id) as $option) {
            $id = hash('sha256', json_encode([$option->type()->value, $option->toArray()], JSON_THROW_ON_ERROR));
            $options[$id] = $option;
        }
        if ($game->phase->isActionPhase() && $game->state->round->hasTakenMainAction
            && $game->state->round->turnStartVersion !== null && $game->state->pendingInteraction === null) {
            $options['finish_turn'] = null;
        }

        return $options;
    }

    /**
     * Get the tool's input schema.
     *
     * @return array<string, Type>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'operation' => $schema->string()->enum(['join_game', 'get_state', 'get_legal_actions', 'stage_action', 'confirm_action', 'rollback_action'])->required(),
            'version' => $schema->integer()->min(0),
            'action_id' => $schema->string(),
            'game_id' => $schema->integer()->min(1)->description('Required for join_game, or when the configured user participates in multiple games.'),
        ];
    }
}
