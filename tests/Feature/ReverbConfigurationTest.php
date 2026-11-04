<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReverbConfigurationTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('connections')]
    public function test_pages_receive_only_public_reverb_settings_from_the_server(int $port, string $scheme): void
    {
        $client = [
            'key' => 'server-public-key',
            'host' => 'websocket.example.test',
            'port' => $port,
            'scheme' => $scheme,
        ];
        config()->set([
            'reverb.client' => $client,
            'reverb.apps.apps.0.secret' => 'private-reverb-secret',
            'broadcasting.connections.reverb.secret' => 'private-broadcasting-secret',
        ]);

        $this->actingAs(User::factory()->create())
            ->get(route('games.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('games/Index')
                ->where('reverb', $client)
                ->missing('reverb.secret'))
            ->assertDontSee('private-reverb-secret')
            ->assertDontSee('private-broadcasting-secret');
    }

    /** @return array<string, array{int, string}> */
    public static function connections(): array
    {
        return [
            'secure connection' => [443, 'https'],
            'local connection' => [8080, 'http'],
        ];
    }
}
