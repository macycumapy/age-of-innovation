<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GamePlayer> */
class GamePlayerFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'game_id' => Game::factory(),
            'user_id' => User::factory(),
            'seat' => fake()->numberBetween(1, 5),
            'color' => null,
            'faction' => null,
            'homeland' => null,
            'is_ready' => false,
            'result_place' => null,
            'final_score' => null,
        ];
    }

    public function ready(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_ready' => true,
        ]);
    }
}
