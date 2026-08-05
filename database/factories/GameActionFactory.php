<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Enums\GameActionType;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GameAction> */
class GameActionFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $versionBefore = fake()->numberBetween(0, 1000);

        return [
            'game_id' => Game::factory(),
            'sequence' => $versionBefore + 1,
            'player_id' => User::factory(),
            'type' => GameActionType::Pass,
            'payload' => [],
            'events' => [],
            'state_version_before' => $versionBefore,
            'state_version_after' => $versionBefore + 1,
        ];
    }
}
