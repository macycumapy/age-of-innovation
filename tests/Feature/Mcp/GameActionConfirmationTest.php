<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BuildWorkshopOptionData;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Economy\Data\ResourceExchangeOptionData;
use App\Domain\GameEngine\Economy\Enums\ResourceExchange;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Setup\Actions\StartGameAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Domain\GameSession\Actions\ConfirmGameAction;
use App\Domain\GameSession\Actions\RollbackGameAction;
use App\Domain\GameSession\Actions\StageGameAction;
use App\Domain\GameSession\Data\ConfirmedGameActionData;
use App\Domain\GameSession\Data\StagedGameActionData;
use App\Domain\GameSession\Enums\GameActionContinuation;
use App\Domain\GameSession\Queries\GetGameTurnContextQuery;
use App\Domain\GameSession\Queries\GetLegalGameActionsQuery;
use App\Events\GameChanged;
use App\Mcp\Presentation\GameActionIdentifier;
use App\Mcp\Presentation\GameActionPresenter;
use App\Mcp\Presentation\GameStatePresenter;
use App\Mcp\Server\GameServer;
use App\Mcp\Stores\GameContextSnapshotStore;
use App\Mcp\Tools\ExecuteGameActionTool;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameActionConfirmationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('continuations')]
    public function test_continuation_prioritizes_game_end_and_pending_choices(GameStatus $status, ?int $activeId, ?int $pendingId, GameActionContinuation $expected): void
    {
        $state = new GameStateData(pendingInteraction: $pendingId === null ? null : new PendingInteractionData(PendingInteractionType::PowerOffer, $pendingId));
        $game = new Game(['status' => $status, 'active_game_player_id' => $activeId, 'state' => $state, 'version' => 19]);
        $game->id = 42;
        $player = new GamePlayer();
        $player->id = 7;
        $player->user_id = 17;

        $continuation = GameActionContinuation::forPlayer($game, $player);
        $this->assertSame($expected, $continuation);
        $result = new ConfirmedGameActionData(
            $game,
            new StagedGameActionData(18, new BuildWorkshopOptionData('8:0')),
            $continuation,
            $continuation === GameActionContinuation::GameEnded ? null : ($pendingId ?? $activeId)
        );
        $payload = app(GameActionPresenter::class)->confirmed($result);

        $this->assertSame([
            'confirmed' => true, 'game_id' => 42, 'version' => 19,
            'action' => ['type' => 'build_workshop', 'parameters' => ['hexId' => '8:0']],
            'continuation' => $expected->value, 'next_player_id' => $result->nextPlayerId,
        ], $payload);
        $this->assertArrayNotHasKey('state', $payload);
        $this->assertArrayNotHasKey('actions', $payload);
    }

    public function test_http_confirmation_returns_a_reconstructable_update_and_next_actions(): void
    {
        [$game, $user] = $this->sessionGame();
        $state = $game->state;
        $state->round->phase = GamePhase::Actions;
        $state->players = [new GamePlayerStateData(
            playerId: $game->active_game_player_id,
            userId: $user->id,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(coins: 5, tools: 3),
        )];
        $game->update(['state' => $state, 'phase' => GamePhase::Actions]);
        $context = app(GameStatePresenter::class)->turnContext(app(GetGameTurnContextQuery::class)->execute($user, $game->id));
        $option = collect($context['actions'])->firstWhere('type', 'exchange_resources');
        $this->assertNotNull($option);
        $before = $game->fresh()->getAttributes();
        $historyCount = $game->actions()->count();
        Event::fake([GameChanged::class]);
        $this->assertSame($before, $game->fresh()->getAttributes());
        config(['game-mcp.user_id' => User::factory()->create()->id]);
        $token = $user->createToken('test', ['game:mcp'])->plainTextToken;
        $request = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'execute_action', 'arguments' => ['game_id' => $game->id, 'version' => $game->version, 'action_id' => $option['action_id']]],
        ];
        config(['game-mcp.metrics.enabled' => true]);
        Log::spy();
        Log::shouldReceive('channel')->with('mcp')->andReturnSelf();
        $response = $this->withToken($token)->postJson('/mcp/game', $request)
            ->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent.confirmed', true)
            ->assertJsonPath('result.structuredContent.game_id', $game->id)
            ->assertJsonPath('result.structuredContent.version', $game->version + 1)
            ->assertJsonPath('result.structuredContent.action', ['type' => $option['type'], 'parameters' => $option['parameters']])
            ->assertJsonMissingPath('result.structuredContent.state')
            ->assertJsonMissingPath('result.structuredContent.actions');

        $game->refresh();
        $payload = $response->json('result.structuredContent');
        $this->assertSame('your_turn', $payload['continuation']);
        $player = $game->players()->where('user_id', $user->id)->sole();
        $this->assertSame(GameActionContinuation::forPlayer($game, $player)->value, $payload['continuation']);
        $this->assertSame($game->state->pendingInteraction?->playerId ?? $game->active_game_player_id, $payload['next_player_id']);
        if ($payload['continuation'] === 'your_turn') {
            $update = $payload['context_update'];
            Log::shouldHaveReceived('info')->withArgs(
                fn (string $message, array $metric): bool =>
                $message === 'mcp.tool_call' && $metric['tool'] === 'execute_action'
                && $metric['response_format'] === $update['format']
                && $metric['actions_count'] === count($update['actions'] ?? $update['context']['actions'])
                && $metric['full_snapshot_reason'] === null
            )->once();
            $cached = app(GameContextSnapshotStore::class)->find($user->id, $game->id, $player->id, $update['context_id']);
            $this->assertNotNull($cached);
            $this->assertSame($game->version, $cached['version']);
            if ($update['format'] === 'context-snapshot-v1') {
                $reconstructed = $update['context'];
            } else {
                $this->assertSame($context['version'], $update['base_version']);
                $this->assertSame($game->id, $update['game_id']);
                $reconstructed = $context;
                foreach ($update['changes'] as $change) {
                    $path = $change['path'];
                    $key = array_pop($path);
                    $target = &$reconstructed;
                    foreach ($path as $segment) {
                        $target = &$target[$segment];
                    }
                    if ($change['op'] === 'remove') {
                        unset($target[$key]);
                    } else {
                        $target[$key] = $change['value'];
                    }
                    unset($target);
                }
                $reconstructed['actions'] = $update['actions'];
            }
            $fresh = app(GameStatePresenter::class)->turnContext(app(GetGameTurnContextQuery::class)->execute($user, $game->id));
            $this->assertSame($fresh, $reconstructed);
            $this->assertSame($fresh, $cached);
            $this->assertLessThan(strlen(json_encode($fresh)), strlen(json_encode($update)));
            $this->assertStringNotContainsString('strategicPlan', json_encode($update));
        } else {
            $this->assertArrayNotHasKey('context_update', $payload);
            $this->assertLessThan(1024, strlen($response->json('result.content.0.text')));
        }
        $this->assertSame($payload, json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame($historyCount + 1, $game->actions()->count());
        Event::assertDispatchedTimes(GameChanged::class, 1);

        $this->assertNotEmpty($reconstructed['actions']);
        config(['game-mcp.user_id' => $user->id]);
        $this->stageSelection($game, $user, $reconstructed['actions'][0]['action_id']);
        app(RollbackGameAction::class)->execute($user, $game->id, $reconstructed['version']);
        $this->assertSame($historyCount + 1, $game->actions()->count());

        $request['params']['arguments']['version'] = $game->version;
        $this->withToken($token)->postJson('/mcp/game', $request)->assertOk()->assertJsonPath('result.isError', true);
        $this->assertSame($historyCount + 1, $game->actions()->count());
        Event::assertDispatchedTimes(GameChanged::class, 1);
    }

    public function test_finishing_turn_returns_wait_instruction_and_next_player_without_full_state(): void
    {
        [$game, $user] = $this->sessionGame();
        $context = app(GameStatePresenter::class)->turnContext(app(GetGameTurnContextQuery::class)->execute($user, $game->id));
        GameServer::tool(ExecuteGameActionTool::class, ['game_id' => $game->id, 'version' => $game->version, 'action_id' => $context['actions'][0]['action_id']])->assertOk();
        $game->refresh();
        $player = $game->players()->where('user_id', $user->id)->sole();
        $state = $game->state;
        $state->round->phase = GamePhase::Actions;
        $state->round->hasTakenMainAction = true;
        $state->round->turnStartVersion = $game->version;
        $game->update(['state' => $state, 'phase' => GamePhase::Actions, 'active_game_player_id' => $player->id]);
        GameServer::tool(ExecuteGameActionTool::class, ['game_id' => $game->id, 'version' => $game->version, 'action_id' => 'finish_turn'])->assertOk()
            ->assertSee('"continuation":"wait_for_turn"')->assertSee('"type":"finish_turn"')
            ->assertDontSee('"state":')->assertDontSee('"actions":');

        $this->assertNotSame($player->id, $game->fresh()->active_game_player_id);
    }

    #[DataProvider('exchangePhases')]
    public function test_bulk_exchange_execution_respects_pending_choices_and_domain_rollback(bool $hasPendingChoice): void
    {
        [$game, $user] = $this->sessionGame();
        $state = $game->state;
        $state->round->phase = GamePhase::Actions;
        $state->players = [new GamePlayerStateData(
            playerId: $game->active_game_player_id,
            userId: $user->id,
            color: PlayerColor::Green,
            faction: Faction::Illusionists,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(power: new PowerBowlsStateData(bowlThree: 5)),
        )];
        $state->pendingInteraction = $hasPendingChoice
            ? new PendingInteractionData(PendingInteractionType::PowerOffer, $game->active_game_player_id, context: ['powerAmount' => 1])
            : null;
        $game->update(['state' => $state, 'phase' => GamePhase::Actions]);
        $context = app(GameStatePresenter::class)->turnContext(app(GetGameTurnContextQuery::class)->execute($user, $game->id));
        $this->assertIsArray($context['actions']);
        $option = null;
        foreach ($context['actions'] as $action) {
            if ($action['type'] === 'exchange_resources'
                && $action['parameters']['exchange'] === 'power_to_coin' && $action['parameters']['amount'] === 3) {
                $option = $action;
                break;
            }
        }
        if ($hasPendingChoice) {
            $this->assertNull($option);
            $before = $game->fresh()->getAttributes();
            $id = app(GameActionIdentifier::class)->forOption(new ResourceExchangeOptionData(ResourceExchange::PowerToCoin, amount: 3), $game->version);
            GameServer::tool(ExecuteGameActionTool::class, ['game_id' => $game->id, 'version' => $game->version, 'action_id' => $id])->assertHasErrors();
            $this->assertSame($before, $game->fresh()->getAttributes());

            return;
        }
        $this->assertNotNull($option);
        $before = $game->fresh()->getAttributes();
        $historyCount = $game->actions()->count();
        $arguments = ['game_id' => $game->id, 'version' => $game->version, 'action_id' => $option['action_id']];

        $unaffordable = app(GameActionIdentifier::class)->forOption(new ResourceExchangeOptionData(ResourceExchange::PowerToCoin, amount: 6), $game->version);
        GameServer::tool(ExecuteGameActionTool::class, [...$arguments, 'action_id' => $unaffordable])->assertHasErrors();
        $this->assertSame($before, $game->fresh()->getAttributes());

        $this->stageSelection($game, $user, $arguments['action_id']);
        $this->assertSame($before, $game->fresh()->getAttributes());
        app(RollbackGameAction::class)->execute($user, $game->id, $game->version);
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertSame($historyCount, $game->actions()->count());
        try {
            app(ConfirmGameAction::class)->execute($user, $game->id, $game->version);
            $this->fail('An unstaged action cannot be confirmed.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('No valid staged action', $exception->getMessage());
        }

        GameServer::tool(ExecuteGameActionTool::class, $arguments)->assertOk();
        $game->refresh();
        $this->assertSame(3, $game->state->players[0]->resources->coins);
        $this->assertSame(2, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame(3, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame($context['version'] + 1, $game->version);
        $this->assertSame($historyCount + 1, $game->actions()->count());
        $action = $game->actions()->latest('id')->firstOrFail();
        $this->assertSame(3, $action->payload['exchanges']['power_to_coin']);
        $this->assertFalse($game->state->round->hasTakenMainAction);
        $this->assertSame($hasPendingChoice, $game->state->pendingInteraction !== null);
        GameServer::tool(ExecuteGameActionTool::class, $arguments)->assertHasErrors();
        $this->assertSame($historyCount + 1, $game->actions()->count());
    }

    /** @return array<string, array{bool}> */
    public static function exchangePhases(): array
    {
        return ['regular turn' => [false], 'pending choice' => [true]];
    }

    /** @return list<array{GameStatus, ?int, ?int, GameActionContinuation}> */
    public static function continuations(): array
    {
        return [
            [GameStatus::Active, 7, null, GameActionContinuation::YourTurn],
            [GameStatus::Active, 8, null, GameActionContinuation::WaitForTurn],
            [GameStatus::Active, 8, 7, GameActionContinuation::YourTurn],
            [GameStatus::Active, 7, 8, GameActionContinuation::WaitForTurn],
            [GameStatus::Active, null, null, GameActionContinuation::WaitForTurn],
            [GameStatus::Finished, 7, 7, GameActionContinuation::GameEnded],
            [GameStatus::Abandoned, 7, null, GameActionContinuation::GameEnded],
            [GameStatus::Lobby, 7, null, GameActionContinuation::WaitForTurn],
        ];
    }

    private function stageSelection(Game $game, User $user, string $id): void
    {
        $options = app(GetLegalGameActionsQuery::class)->execute($user, $game->id);
        app(StageGameAction::class)->execute($user, $game->id, $game->version, app(GameActionIdentifier::class)->resolve($id, $options));
    }

    /** @return array{Game, User} */
    private function sessionGame(): array
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'confirmation-summary']);
        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => $index + 1]);
        }
        $game = app(StartGameAction::class)->execute($game, $users[0]);
        $user = $game->activeGamePlayer->user;
        config(['game-mcp.user_id' => $user->id]);

        return [$game, $user];
    }
}
