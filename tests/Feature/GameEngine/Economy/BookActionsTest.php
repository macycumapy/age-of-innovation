<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Economy;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Enums\BookAction;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BookActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_can_activate_a_book_action_only_once_per_round(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->create(2);
        $setupPool->bookActions = [
            BookAction::GainCoins,
            BookAction::GainPower,
            BookAction::ScoreGuilds,
        ];
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                usedSharedActionIds: [PowerAction::GainCoins->value],
            ),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(
                    books: new BookSupplyData(banking: 1, law: 1),
                ),
            )],
            setupPool: $setupPool,
        )]);

        $this->actingAs($user)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->has('game.data.bookActionStates', 3)
                    ->where('game.data.bookActionStates.0.id', BookAction::GainCoins->value)
                    ->where('game.data.bookActionStates.0.cost', 2)
                    ->where('game.data.bookActionStates.0.isUsed', false),
            );

        $payload = [
            'action' => BookAction::GainCoins->value,
            'book_counts' => [
                'banking' => 1,
                'law' => 1,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ];
        $this->actingAs($user)
            ->post(route('games.book-action', $game), $payload)
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(6, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->state->players[0]->resources->books->banking);
        $this->assertSame(0, $game->state->players[0]->resources->books->law);
        $this->assertContains(BookAction::GainCoins->value, $game->state->round->usedBookActionIds);
        $this->assertSame(GameActionType::BookAction, $game->actions()->sole()->type);

        $this->post(route('games.book-action', $game), $payload)->assertSessionHasErrors('action');
        $this->assertSame(1, $game->actions()->count());
    }

    public function test_book_knowledge_action_scores_each_actual_step_for_the_round_goal(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->create(2);
        $setupPool->bookActions = [BookAction::AdvanceKnowledge];
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::KnowledgeMedicine->value,
            ),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(
                    books: new BookSupplyData(law: 1),
                ),
            )],
            setupPool: $setupPool,
        )]);

        $this->actingAs($user)->post(route('games.book-action', $game), [
            'action' => BookAction::AdvanceKnowledge->value,
            'book_counts' => [
                'banking' => 0,
                'law' => 1,
                'engineering' => 0,
                'medicine' => 0,
            ],
            'discipline' => KnowledgeDiscipline::Law->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->knowledge->law);
        $this->assertSame(22, $game->state->players[0]->victoryPoints);
        $this->assertSame(2, $game->actions()->sole()->payload['victory_points']);
    }
}
