<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\TwoPlayerTerritoryScore;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Models\Game;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TwoPlayerTerritoryTileVisibilityTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_hides_the_territory_value_until_round_six(): void
    {
        $user = User::factory()->create();
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'territory-visibility-test');
        $setupPool->twoPlayerTerritoryScore = TwoPlayerTerritoryScore::Fourteen;
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'state' => new GameStateData(
                round: new RoundStateData(number: 5, phase: GamePhase::Actions),
                setupPool: $setupPool,
            ),
        ]);
        $game->players()->create([
            'user_id' => $user->id,
            'seat' => 1,
            'is_ready' => true,
        ]);

        $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where('game.data.twoPlayerTerritoryTile', 'unknown'),
            );

        $state = $game->state;
        $state->round->number = 6;
        $game->update(['state' => $state]);

        $this->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where('game.data.twoPlayerTerritoryTile', 14),
            );
    }
}
