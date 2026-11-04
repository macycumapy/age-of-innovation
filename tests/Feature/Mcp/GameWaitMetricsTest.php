<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\GameSession\Enums\GameTurnWaitReason;
use App\Domain\GameSession\Queries\WaitForGameTurnQuery;
use App\Domain\GameSession\Services\GameChangeSignal;
use App\Domain\GameSession\Services\GameWaitMetrics;
use App\Domain\GameSession\Services\RedisGameChangeSignal;
use App\Models\Game;
use App\Models\GamePlayer;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\LogManager;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Sleep;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

final class GameWaitMetricsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<array{level: string, event: string, context: array<string, mixed>}> */
    private array $records = [];

    private GameWaitMetrics $metrics;

    protected function setUp(): void
    {
        parent::setUp();
        config(['game-mcp.metrics.enabled' => true, 'game-mcp.wait.enabled' => true]);
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')->andReturnUsing(function (string $level, string $event, array $context): void {
            $this->records[] = ['level' => $level, 'event' => $event, 'context' => $context];
        });
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->with('mcp')->andReturn($logger);
        $this->metrics = new GameWaitMetrics($logs);
        $this->app->instance(GameWaitMetrics::class, $this->metrics);
    }

    protected function tearDown(): void
    {
        Sleep::fake(false);
        parent::tearDown();
    }

    #[DataProvider('redisOperations')]
    public function test_redis_errors_are_measured_without_logging_exception_messages(string $operation, string $connectionName, string $command): void
    {
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->once()->with($connectionName)
            ->andThrow(new RuntimeException('redis://user:secret@private-host token=private-token cursor=123-4'));
        $signals = new RedisGameChangeSignal($factory, $this->metrics);

        $this->operate($signals, $operation);

        $this->assertSame([[
            'level' => 'warning', 'event' => 'mcp.redis_error',
            'context' => ['game_id' => 7, 'operation' => $operation, 'error_type' => RuntimeException::class],
        ]], $this->records);
        $this->assertStringNotContainsString('secret', json_encode($this->records));
        $this->assertStringNotContainsString('private-host', json_encode($this->records));
        $this->assertStringNotContainsString('private-token', json_encode($this->records));
        $this->assertStringNotContainsString('123-4', json_encode($this->records));
    }

    #[DataProvider('redisOperations')]
    public function test_invalid_redis_responses_are_measured(string $operation, string $connectionName, string $command): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('command')->once()->with($command, Mockery::type('array'))->andReturn('invalid');
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->once()->with($connectionName)->andReturn($connection);

        $this->operate(new RedisGameChangeSignal($factory, $this->metrics), $operation);

        $this->assertSame('mcp.redis_error', $this->records[0]['event']);
        $this->assertSame(['game_id' => 7, 'operation' => $operation, 'error_type' => 'invalid_response'], $this->records[0]['context']);
    }

    /** @return list<array{string, string, string}> */
    public static function redisOperations(): array
    {
        return [['cursor', 'game_signals', 'xrevrange'], ['wait', 'game_wait', 'xread'], ['publish', 'game_signals', 'eval']];
    }

    public function test_wait_failure_records_one_transition_to_polling(): void
    {
        [$game, $player] = $this->createGameSession();
        Sleep::fake();
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldReceive('cursor')->once()->andReturn('0-0');
        $signals->shouldReceive('wait')->once()->andReturnNull();
        $this->app->instance(GameChangeSignal::class, $signals);

        $result = app(WaitForGameTurnQuery::class)->execute($player->user, $game->id, 2);

        $this->assertSame(GameTurnWaitReason::Timeout, $result->reason);
        $this->assertSame([
            ['level' => 'info', 'event' => 'mcp.wait_transport', 'context' => ['game_id' => $game->id, 'transport' => 'redis', 'reason' => null]],
            ['level' => 'info', 'event' => 'mcp.wait_transport', 'context' => ['game_id' => $game->id, 'transport' => 'poll', 'reason' => 'redis_unavailable']],
        ], $this->records);
        Sleep::assertSleptTimes(2);
    }

    #[DataProvider('pollingReasons')]
    public function test_initial_polling_distinguishes_disabled_redis_from_unavailable_redis(bool $enabled, string $reason): void
    {
        [$game, $player] = $this->createGameSession();
        config(['game-mcp.wait.enabled' => $enabled]);
        Sleep::fake();
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldReceive('cursor')->once()->andReturnNull();
        $signals->shouldNotReceive('wait');
        $this->app->instance(GameChangeSignal::class, $signals);

        app(WaitForGameTurnQuery::class)->execute($player->user, $game->id, 2);

        $this->assertSame([[
            'level' => 'info', 'event' => 'mcp.wait_transport',
            'context' => ['game_id' => $game->id, 'transport' => 'poll', 'reason' => $reason],
        ]], $this->records);
    }

    /** @return list<array{bool, string}> */
    public static function pollingReasons(): array
    {
        return [[true, 'redis_unavailable'], [false, 'disabled']];
    }

    public function test_normal_redis_timeout_is_not_an_error_or_fallback(): void
    {
        [$game, $player] = $this->createGameSession();
        $signals = Mockery::mock(GameChangeSignal::class);
        $signals->shouldReceive('cursor')->once()->andReturn('0-0');
        $signals->shouldReceive('wait')->once()->andReturn('0-0');
        $this->app->instance(GameChangeSignal::class, $signals);

        app(WaitForGameTurnQuery::class)->execute($player->user, $game->id, 2);

        $this->assertCount(1, $this->records);
        $this->assertSame(['game_id' => $game->id, 'transport' => 'redis', 'reason' => null], $this->records[0]['context']);
    }

    public function test_disabled_metrics_never_access_the_logger(): void
    {
        config(['game-mcp.metrics.enabled' => false]);
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldNotReceive('channel');
        $metrics = new GameWaitMetrics($logs);

        $metrics->transport(7, 'poll', 'redis_unavailable');
        $metrics->redisError(7, 'publish', new RuntimeException('secret'));

        $this->assertSame([], $this->records);
    }

    public function test_a_native_redis_timeout_does_not_record_an_error(): void
    {
        $connection = Mockery::mock(Connection::class);
        $connection->shouldReceive('command')->once()->with('xread', Mockery::type('array'))->andReturn(false);
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->once()->with('game_wait')->andReturn($connection);
        $signals = new RedisGameChangeSignal($factory, $this->metrics);

        $this->assertSame('0-0', $signals->wait(7, '0-0', 1));
        $this->assertSame([], $this->records);
    }

    public function test_an_immediate_check_does_not_record_a_wait_transport(): void
    {
        [$game, $player] = $this->createGameSession();

        $result = app(WaitForGameTurnQuery::class)->execute($player->user, $game->id, 0);

        $this->assertSame(GameTurnWaitReason::Timeout, $result->reason);
        $this->assertSame([], $this->records);
    }

    public function test_logger_failures_do_not_interrupt_redis_failure_handling(): void
    {
        $logs = Mockery::mock(LogManager::class);
        $logs->shouldReceive('channel')->andThrow(new RuntimeException('Disk unavailable'));
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('connection')->andThrow(new RuntimeException('Redis unavailable'));
        $signals = new RedisGameChangeSignal($factory, new GameWaitMetrics($logs));

        $this->assertNull($signals->cursor(7));
        $this->assertNull($signals->wait(7, '0-0', 1));
        $signals->publish(7);
    }

    private function operate(RedisGameChangeSignal $signals, string $operation): void
    {
        match ($operation) {
            'cursor' => $this->assertNull($signals->cursor(7)),
            'wait' => $this->assertNull($signals->wait(7, '0-0', 1)),
            'publish' => $signals->publish(7),
        };
    }

    /** @return array{Game, GamePlayer} */
    private function createGameSession(): array
    {
        $game = Game::factory()->active()->create();
        $player = GamePlayer::factory()->for($game)->create();

        return [$game, $player];
    }
}
