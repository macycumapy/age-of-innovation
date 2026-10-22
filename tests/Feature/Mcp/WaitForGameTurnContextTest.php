<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Board\Factories\BoardStateFactory;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Mcp\Server\GameServer;
use App\Mcp\Stores\GameContextSnapshotStore;
use App\Mcp\Tools\WaitForGameTurnTool;
use App\Mcp\Validators\WaitForGameTurnValidator;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class WaitForGameTurnContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_http_wait_delta_restores_exact_context_and_can_be_used_as_the_next_baseline(): void
    {
        config(['cache.stores.array.serialize' => true, 'cache.serializable_classes' => false]);
        Cache::purge('array');
        [$game, $player, $token] = $this->createGameSession();
        $baseline = $this->toolCall('get_turn_context', ['game_id' => $game->id], $token);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $baseline['context_id']);
        $state = $game->state;
        $state->round->hasTakenMainAction = true;
        $game->update(['state' => $state, 'version' => 2]);
        $attributes = $game->fresh()->getAttributes();

        $response = $this->toolCall('wait_for_turn', ['game_id' => $game->id, 'timeout_seconds' => 0, 'context_id' => $baseline['context_id']], $token);
        $this->assertSame('your_turn', $response['reason']);
        $update = $response['context_update'];
        $this->assertSame('context-delta-v1', $update['format']);
        $this->assertSame($baseline['context_id'], $update['base_context_id']);
        $this->assertSame(1, $update['base_version']);
        $this->assertSame(2, $update['version']);
        $this->assertNotSame($baseline['context_id'], $update['context_id']);
        $restored = $this->applyUpdate($baseline, $update);
        $fresh = $this->toolCall('get_turn_context', ['game_id' => $game->id], $token);
        unset($restored['context_id'], $fresh['context_id']);
        $this->assertSame($fresh, $restored);
        $this->assertLessThan(strlen(json_encode($fresh)), strlen(json_encode($response)));
        $this->assertSame($attributes, $game->fresh()->getAttributes());
        $this->assertSame(0, $game->actions()->count());
        $this->assertStringNotContainsString('strategicPlan', json_encode($response));

        $again = $this->toolCall('wait_for_turn', ['game_id' => $game->id, 'timeout_seconds' => 0, 'context_id' => $update['context_id']], $token);
        $this->assertSame([], $again['context_update']['changes']);
        $this->assertSame(2, $again['context_update']['base_version']);
        $this->assertSame($update['context_id'], $again['context_update']['base_context_id']);
        config(['game-mcp.user_id' => $player->user_id]);
        GameServer::tool(WaitForGameTurnTool::class, ['game_id' => $game->id, 'timeout_seconds' => 0, 'context_id' => $again['context_update']['context_id']])
            ->assertOk()->assertSee('context-delta-v1')->assertDontSee('"state":');
    }

    #[DataProvider('fallbacks')]
    public function test_unusable_baselines_return_full_context(string $case): void
    {
        [$game, $player, $token] = $this->createGameSession();
        $baseline = $this->toolCall('get_turn_context', ['game_id' => $game->id], $token);
        $id = $baseline['context_id'];
        $store = app(GameContextSnapshotStore::class);
        if ($case === 'new-round') {
            $state = $game->state;
            $state->round->number++;
            $game->update(['state' => $state, 'version' => 2]);
        } elseif ($case === 'expired') {
            $this->travel(GameContextSnapshotStore::TTL_SECONDS + 1)->seconds();
        } elseif ($case === 'missing') {
            $id = str_repeat('0', 32);
        } elseif ($case === 'corrupt') {
            Cache::put("mcp:context:{$player->user_id}:{$game->id}:{$player->id}:{$id}", ['format' => 'compact-v2'], 3600);
        } elseif ($case === 'foreign-user') {
            $baseline['user_id']++;
            $id = $store->remember($baseline)['context_id'];
        } elseif ($case === 'foreign-game') {
            $baseline['game_id']++;
            $id = $store->remember($baseline)['context_id'];
        } elseif ($case === 'foreign-player') {
            $baseline['player_id']++;
            $id = $store->remember($baseline)['context_id'];
        } elseif ($case === 'future-version') {
            $baseline['version'] = 99;
            $id = $store->remember($baseline)['context_id'];
        } elseif ($case === 'larger-delta') {
            $baseline['state'] = [];
            $id = $store->remember($baseline)['context_id'];
        }

        $response = $this->toolCall('wait_for_turn', ['game_id' => $game->id, 'timeout_seconds' => 0, 'context_id' => $id], $token);
        $this->assertSame('compact-v2', $response['format']);
        $this->assertArrayNotHasKey('context_update', $response);
        $this->assertSame($game->id, $response['game_id']);
        $this->assertSame($player->user_id, $response['user_id']);
        $this->assertArrayHasKey('actions', $response);
        $this->assertArrayHasKey('context_id', $response);
        if ($case === 'new-round') {
            $this->assertSame(2, $response['state']['round']['number']);
            $again = $this->toolCall('wait_for_turn', ['game_id' => $game->id, 'timeout_seconds' => 0, 'context_id' => $response['context_id']], $token);
            $this->assertSame('context-delta-v1', $again['context_update']['format']);
            $this->assertSame([], $again['context_update']['changes']);
        }
    }

    /** @return list<array{string}> */
    public static function fallbacks(): array
    {
        return array_map(fn (string $case): array => [$case], ['new-round', 'expired', 'missing', 'corrupt', 'foreign-user', 'foreign-game', 'foreign-player', 'future-version', 'larger-delta']);
    }

    public function test_timeout_neither_reads_nor_writes_snapshot_cache(): void
    {
        [$game, , $token] = $this->createGameSession();
        $game->update(['active_game_player_id' => null]);
        Cache::spy();
        $response = $this->toolCall('wait_for_turn', ['game_id' => $game->id, 'timeout_seconds' => 0, 'context_id' => str_repeat('a', 32)], $token);
        $this->assertSame(['reason' => 'timeout'], $response);
        Cache::shouldNotHaveReceived('get');
        Cache::shouldNotHaveReceived('put');
    }

    public function test_context_id_does_not_bypass_game_membership(): void
    {
        [$game, , $token] = $this->createGameSession();
        $baseline = $this->toolCall('get_turn_context', ['game_id' => $game->id], $token);
        $otherToken = User::factory()->create()->createToken('test', ['game:mcp'])->plainTextToken;
        $this->app['auth']->forgetGuards();
        $this->withToken($otherToken)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'wait_for_turn', 'arguments' => ['game_id' => $game->id, 'timeout_seconds' => 0, 'context_id' => $baseline['context_id']]],
        ])->assertOk()->assertJsonPath('result.isError', true)
            ->assertJsonMissingPath('result.structuredContent');
    }

    public function test_snapshot_cache_outage_is_optional_and_corrupt_metadata_is_rejected(): void
    {
        [$game, $player, $token] = $this->createGameSession();
        $baseline = $this->toolCall('get_turn_context', ['game_id' => $game->id], $token);
        $store = app(GameContextSnapshotStore::class);
        $key = "mcp:context:{$player->user_id}:{$game->id}:{$player->id}:{$baseline['context_id']}";
        $corrupt = $baseline;
        $corrupt['user_id']++;
        Cache::put($key, $corrupt, 3600);
        $this->assertNull($store->find($player->user_id, $game->id, $player->id, $baseline['context_id']));
        Cache::shouldReceive('get')->once()->andThrow(new RuntimeException('Cache unavailable'));
        Cache::shouldReceive('put')->once()->andThrow(new RuntimeException('Cache unavailable'));
        $this->assertNull($store->find($player->user_id, $game->id, $player->id, $baseline['context_id']));
        $uncached = $store->remember($baseline);
        $this->assertArrayNotHasKey('context_id', $uncached);
        unset($baseline['context_id']);
        $this->assertSame($baseline, $uncached);
    }

    #[DataProvider('invalidIds')]
    public function test_context_id_validation_rejects_invalid_values(mixed $id): void
    {
        $this->expectException(ValidationException::class);
        (new WaitForGameTurnValidator())->validate(new Request(['context_id' => $id]));
    }

    /** @return list<array{mixed}> */
    public static function invalidIds(): array
    {
        return [[null], [''], [[]], [12], [str_repeat('a', 31)], [str_repeat('a', 33)], [str_repeat('G', 32)], [str_repeat('a', 32)."\n"], ['../../.env']];
    }

    /** @return array{Game, GamePlayer, string} */
    private function createGameSession(): array
    {
        $state = new GameStateData(board: app(BoardStateFactory::class)->create(MapVariant::ThreeToFivePlayers));
        $game = Game::factory()->active()->create(['state' => $state, 'version' => 1]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id]);
        $game->update(['active_game_player_id' => $player->id]);

        return [$game, $player, $player->user->createToken('test', ['game:mcp'])->plainTextToken];
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function toolCall(string $name, array $arguments, string $token): array
    {
        $response = $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call', 'params' => ['name' => $name, 'arguments' => $arguments],
        ])->assertOk()->assertJsonPath('result.isError', false);
        $this->assertSame($response->json('result.structuredContent'), json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR));

        return $response->json('result.structuredContent');
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $update
     * @return array<string, mixed>
     */
    private function applyUpdate(array $context, array $update): array
    {
        foreach ($update['changes'] as $change) {
            $path = $change['path'];
            $key = array_pop($path);
            $target = &$context;
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
        $context['actions'] = $update['actions'];
        $context['context_id'] = $update['context_id'];

        return $context;
    }
}
