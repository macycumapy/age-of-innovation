<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Setup;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class StartGameTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_can_start_game_when_all_players_are_ready(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create(['random_seed' => 'repeatable-game-seed']);
        $ownerPlayer = GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        $secondPlayer = GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)
            ->post(route('games.start', $game))
            ->assertNoContent();

        $game->refresh();

        $this->assertSame(GameStatus::Active, $game->status);
        $this->assertSame(GamePhase::Setup, $game->phase);
        $this->assertNotNull($game->started_at);
        $this->assertNotNull($game->current_turn_started_at);
        $this->assertSame(1, $game->version);
        $this->assertNotNull($game->state->setupPool);
        $this->assertSame(2, $game->state->setupPool->playerCount);
        $this->assertSame($game->state->board->variant, $game->state->setupPool->mapVariant);
        $this->assertIsString($game->state->setupPool->roundScoringTiles[0]);
        $this->assertIsString($game->state->setupPool->bookActions[0]);
        $this->assertSame(
            $game->state->setupPool->roundScoringTiles[0],
            $game->state->round->scoringTileId,
        );
        $this->assertCount(21, $game->state->availableTownTileIds);
        $this->assertCount(4, $game->state->availablePalaceIds);
        $this->assertCount(6, $game->state->availableInventionIds);
        $this->assertCount(48, $game->state->availableCompetencyIds);
        $this->assertSame(
            4,
            array_count_values($game->state->availableCompetencyIds)[Competency::Competency01->value],
        );
        $this->assertCount(10, $game->state->roundBonusIds);
        $this->assertEqualsCanonicalizing(
            [$ownerPlayer->id, $secondPlayer->id],
            $game->state->turnOrder,
        );
        $this->assertContains($game->active_player_id, [$owner->id, $secondUser->id]);

        $startAction = $game->actions()->where('type', GameActionType::StartGame)->sole();
        $this->assertSame(GameActionType::StartGame, $startAction->type);
        $this->assertSame(1, $startAction->sequence);
        $this->assertSame(0, $startAction->state_version_before);
        $this->assertSame(1, $startAction->state_version_after);
        $this->assertSame('game_started', $startAction->events[0]['type']);
        $this->assertSame($game->random_seed, $startAction->events[0]['random_seed']);

        $this->actingAs($owner)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.turnOrder', $game->state->turnOrder)
                    ->where('game.data.activePlayerId', $game->active_player_id)
                    ->where('game.data.currentTurnStartedAt', $game->current_turn_started_at->toISOString())
                    ->has('game.data.history.data', 2)
                    ->where('game.data.history.hasMore', false)
                    ->where('game.data.history.data.0.sequence', 2)
                    ->where('game.data.history.data.0.type', GameActionType::PhaseCheckpoint->value)
                    ->where('game.data.history.data.0.player', null)
                    ->where('game.data.history.data.1.type', GameActionType::StartGame->value)
                    ->where(
                        'game.data.availablePalaceIds',
                        $game->state->availablePalaceIds,
                    )
                    ->where(
                        'game.data.availableTownTileIds',
                        $game->state->availableTownTileIds,
                    )
                    ->has('game.data.roundBonusOffers', 3)
                    ->where(
                        'game.data.roundBonusOffers.0.roundBonus',
                        $game->state->setupPool
                            ->availableRoundBonuses[0]
                            ->roundBonus
                            ->value,
                    )
                    ->where(
                        'game.data.roundBonusOffers.0.coins',
                        $game->state->setupPool
                            ->availableRoundBonuses[0]
                            ->coins,
                    ),
            );
    }

    public function test_only_owner_can_start_game(): void
    {
        $owner = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($secondUser)
            ->post(route('games.start', $game))
            ->assertForbidden();

        $this->assertSame(GameStatus::Lobby, $game->refresh()->status);
        $this->assertNull($game->state->setupPool);
    }

    public function test_game_cannot_start_until_all_players_are_ready(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
            'is_ready' => false,
        ]);

        $this->actingAs($owner)
            ->post(route('games.start', $game))
            ->assertSessionHasErrors('game');

        $this->assertSame(GameStatus::Lobby, $game->refresh()->status);
    }

    public function test_game_requires_at_least_two_players_to_start(): void
    {
        $owner = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);

        $this->actingAs($owner)
            ->post(route('games.start', $game))
            ->assertSessionHasErrors('game');

        $this->assertSame(GameStatus::Lobby, $game->refresh()->status);
    }
}
