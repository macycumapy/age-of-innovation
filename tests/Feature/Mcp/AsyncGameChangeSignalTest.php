<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Server\Services\AsyncGameChangeSignal;
use App\Mcp\Server\Services\GameWaitMetrics;
use App\Mcp\Server\Services\RedisGameChangeSignal;
use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use Illuminate\Redis\RedisManager;
use Mockery;
use React\EventLoop\StreamSelectLoop;

use function React\Promise\all;

use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

use Tests\TestCase;
use Throwable;

final class AsyncGameChangeSignalTest extends TestCase
{
    private StreamSelectLoop $loop;

    private AsyncGameChangeSignal $signals;

    private RedisGameChangeSignal $publisher;

    private RedisManager $redis;

    private array $clients = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->loop = new StreamSelectLoop();
        config(['game-mcp.wait.enabled' => true, 'game-mcp.metrics.enabled' => false, 'database.redis.options.prefix' => 'test:async-game-signal:'.bin2hex(random_bytes(8)).':']);
        $configuration = config('database.redis');
        $this->redis = new RedisManager($this->app, $configuration['client'], $configuration);
        $this->publisher = new RedisGameChangeSignal($this->redis, app(GameWaitMetrics::class));
        $this->signals = new AsyncGameChangeSignal(new Factory($this->loop));
    }

    protected function tearDown(): void
    {
        foreach ($this->clients as $client) {
            $client->close();
        }
        $this->redis->connection('game_signals')->del('game:turn-signals:1');
        $this->redis->purge('game_signals');
        parent::tearDown();
    }

    public function test_an_event_published_before_reading_is_retained(): void
    {
        $client = $this->client();
        $cursor = $this->await($this->signals->cursor($client, 1));
        $this->assertSame('0-0', $cursor);
        $this->publisher->publish(1);
        $next = $this->await($this->signals->wait($client, 1, $cursor, 1000));
        $this->assertNotSame($cursor, $next);
        $this->assertSame($next, $this->await($this->signals->cursor($client, 1)));
    }

    public function test_multiple_waits_receive_the_same_event_without_blocking_timers(): void
    {
        $first = $this->client();
        $second = $this->client();
        $responsive = false;
        $this->loop->addTimer(0.005, function () use (&$responsive): void {
            $responsive = true;
        });
        $this->loop->addTimer(0.02, fn () => $this->publisher->publish(1));
        $results = $this->await(all([$this->signals->wait($first, 1, '0-0', 1000), $this->signals->wait($second, 1, '0-0', 1000)]));
        $this->assertTrue($responsive);
        $this->assertNotSame('0-0', $results[0]);
        $this->assertSame($results[0], $results[1]);
    }

    public function test_a_normal_timeout_returns_the_same_cursor(): void
    {
        $this->assertSame('0-0', $this->await($this->signals->wait($this->client(), 1, '0-0', 10)));
    }

    public function test_acl_credentials_are_applied_before_selecting_the_database(): void
    {
        config(['database.redis.game_signals' => ['url' => null, 'host' => 'example.test', 'port' => 6379, 'database' => 2, 'username' => 'worker', 'password' => 'secret']]);
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('auth')->once()->with('worker', 'secret')->andReturn(resolve('OK'));
        $client->shouldReceive('select')->once()->with('2')->andReturn(resolve('OK'));
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('createClient')->once()->with('redis://example.test:6379?timeout=1')->andReturn(resolve($client));
        $this->assertSame($client, $this->await((new AsyncGameChangeSignal($factory))->connect()));
    }

    public function test_cancelling_during_authentication_closes_the_connection(): void
    {
        config(['database.redis.game_signals.password' => 'secret', 'database.redis.game_signals.username' => null]);
        $authentication = new Deferred();
        $closed = false;
        $client = Mockery::mock(Client::class);
        $client->shouldReceive('auth')->once()->andReturn($authentication->promise());
        $client->shouldReceive('close')->once()->andReturnUsing(function () use (&$closed): void {
            $closed = true;
        });
        $factory = Mockery::mock(Factory::class);
        $factory->shouldReceive('createClient')->once()->andReturn(resolve($client));
        $promise = (new AsyncGameChangeSignal($factory))->connect();
        $promise->cancel();
        $this->assertTrue($closed);
    }

    private function client(): Client
    {
        $client = $this->await($this->signals->connect());
        $this->clients[] = $client;

        return $client;
    }

    private function await(PromiseInterface $promise): mixed
    {
        $completed = false;
        $result = $failure = null;
        $promise->then(function (mixed $value) use (&$completed, &$result): void {
            $completed = true;
            $result = $value;
            $this->loop->stop();
        }, function (Throwable $exception) use (&$completed, &$failure): void {
            $completed = true;
            $failure = $exception;
            $this->loop->stop();
        });
        if (! $completed) {
            $timer = $this->loop->addTimer(3, fn () => $this->loop->stop());
            $this->loop->run();
            $this->loop->cancelTimer($timer);
        }
        $this->assertTrue($completed, 'Asynchronous Redis operation did not complete.');
        if ($failure !== null) {
            throw $failure;
        }

        return $result;
    }
}
