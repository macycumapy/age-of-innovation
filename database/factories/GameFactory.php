<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Game> */
class GameFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'status' => 'lobby',
            'round' => 1,
            'phase' => 'setup',
            'active_player_id' => null,
            'version' => 0,
            'state' => ['schemaVersion' => 1],
            'rules_version' => '1.2',
            'random_seed' => Str::random(32),
            'started_at' => null,
            'finished_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'active',
            'phase' => 'income',
            'started_at' => now(),
        ]);
    }

    public function finished(): static
    {
        return $this->state(fn (array $attributes): array => [
            'status' => 'finished',
            'phase' => 'finished',
            'started_at' => now()->subHour(),
            'finished_at' => now(),
        ]);
    }
}
