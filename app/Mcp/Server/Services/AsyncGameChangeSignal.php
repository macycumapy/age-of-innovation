<?php

declare(strict_types=1);

namespace App\Mcp\Server\Services;

use App\Mcp\Data\AsyncRedisConnectionData;
use Clue\React\Redis\Client;
use Clue\React\Redis\Factory;
use Illuminate\Support\ConfigurationUrlParser;
use React\Promise\Deferred;
use React\Promise\PromiseInterface;

use function React\Promise\reject;
use function React\Promise\resolve;

use RuntimeException;
use Throwable;

final class AsyncGameChangeSignal
{
    public function __construct(private Factory $factory)
    {
    }

    /** @return PromiseInterface<Client> */
    public function connect(): PromiseInterface
    {
        $config = (new ConfigurationUrlParser())->parseConfiguration(config('database.redis.game_signals'));
        $host = (string) $config['host'];
        $secure = ($config['driver'] ?? '') === 'rediss' || str_starts_with($host, 'tls://');
        $host = preg_replace('#^(?:tcp|tls)://#', '', $host);
        $host = str_contains($host, ':') && ! str_starts_with($host, '[') ? '['.$host.']' : $host;
        $uri = str_starts_with($host, '/') ? 'redis+unix://'.$host : ($secure ? 'rediss://' : 'redis://').$host.':'.$config['port'];

        $state = new AsyncRedisConnectionData();
        try {
            $connecting = $this->factory->createClient($uri.'?timeout=1');
        } catch (Throwable $exception) {
            return reject($exception);
        }
        $result = new Deferred(function () use ($state, $connecting): void {
            $state->cancelled = true;
            $connecting->cancel();
            $state->client?->close();
        });
        $connecting->then(function (Client $client) use ($config, $state): PromiseInterface {
            $state->client = $client;
            if ($state->cancelled) {
                $client->close();

                throw new RuntimeException('Redis connection cancelled.');
            }
            $username = (string) ($config['username'] ?? '');
            $password = (string) ($config['password'] ?? '');
            $authenticated = $username !== '' || $password !== ''
                ? $client->__call('auth', $username === '' ? [$password] : [$username, $password]) : resolve(null);

            return $authenticated->then(fn () => $client->__call('select', [(string) $config['database']]))
                ->then(fn () => $client)
                ->then(null, function (Throwable $exception) use ($client): never {
                    $client->close();

                    throw $exception;
                });
        })->then(fn (Client $client) => $result->resolve($client), fn (Throwable $exception) => $result->reject($exception));

        return $result->promise();
    }

    /** @return PromiseInterface<string> */
    public function cursor(Client $client, int $gameId): PromiseInterface
    {
        return $client->__call('xrevrange', [$this->key($gameId), '+', '-', 'COUNT', '1'])->then(function (mixed $entries): string {
            return $entries === [] ? '0-0' : $this->id(is_array($entries) ? ($entries[0][0] ?? null) : null);
        });
    }

    /** @return PromiseInterface<string> */
    public function wait(Client $client, int $gameId, string $cursor, int $milliseconds): PromiseInterface
    {
        return $client->__call('xread', ['COUNT', (string) RedisGameChangeSignal::MAX_ENTRIES, 'BLOCK', (string) max(1, $milliseconds), 'STREAMS', $this->key($gameId), $cursor])
            ->then(function (mixed $streams) use ($cursor): string {
                if ($streams === null || $streams === []) {
                    return $cursor;
                }
                $entries = is_array($streams) ? ($streams[0][1] ?? null) : null;
                $last = is_array($entries) ? end($entries) : null;

                return $this->id(is_array($last) ? ($last[0] ?? null) : null);
            });
    }

    private function id(mixed $id): string
    {
        if (! is_string($id) || preg_match('/\A[0-9]+-[0-9]+\z/', $id) !== 1) {
            throw new RuntimeException('Invalid Redis stream response.');
        }

        return $id;
    }

    private function key(int $gameId): string
    {
        return config('database.redis.options.prefix')."game:turn-signals:{$gameId}";
    }
}
