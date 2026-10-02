<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Research;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Actions\AdvanceDevelopmentTrackAction;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DevelopmentAdvancementTest extends TestCase
{
    use RefreshDatabase;

    public function test_shipping_advancement_grants_rewards_for_each_reached_level(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $advanceDevelopmentTrack = app(AdvanceDevelopmentTrackAction::class);

        $advanceDevelopmentTrack->advanceShipping($playerState);
        $this->assertSame(1, $playerState->shippingLevel);
        $this->assertSame(22, $playerState->victoryPoints);
        $this->assertSame(0, $playerState->resources->books->unassigned);

        $advanceDevelopmentTrack->advanceShipping($playerState);
        $this->assertSame(2, $playerState->shippingLevel);
        $this->assertSame(22, $playerState->victoryPoints);
        $this->assertSame(2, $playerState->resources->books->unassigned);

        $advanceDevelopmentTrack->advanceShipping($playerState);
        $this->assertSame(3, $playerState->shippingLevel);
        $this->assertSame(26, $playerState->victoryPoints);
        $this->assertSame(2, $playerState->resources->books->unassigned);
    }

    public function test_blue_shipping_advancement_uses_its_own_reward_track(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Blue,
            faction: Faction::Navigators,
            homeland: TerrainType::Lake,
            roundBonus: RoundBonus::Coins,
            shippingLevel: 1,
        );
        $advanceDevelopmentTrack = app(AdvanceDevelopmentTrackAction::class);

        $secondLevelReward = $advanceDevelopmentTrack->advanceShipping($playerState);

        $this->assertSame(2, $playerState->shippingLevel);
        $this->assertSame(23, $playerState->victoryPoints);
        $this->assertSame(0, $playerState->resources->books->unassigned);
        $this->assertSame(['steps' => 1, 'books' => 0, 'victoryPoints' => 3], $secondLevelReward);

        $thirdLevelReward = $advanceDevelopmentTrack->advanceShipping($playerState);

        $this->assertSame(3, $playerState->shippingLevel);
        $this->assertSame(23, $playerState->victoryPoints);
        $this->assertSame(2, $playerState->resources->books->unassigned);
        $this->assertSame(['steps' => 1, 'books' => 2, 'victoryPoints' => 0], $thirdLevelReward);
    }

    public function test_player_can_confirm_shipping_advancement_and_restart_the_turn(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::TrackEngineering->value,
            ),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(coins: 4, scholars: 1),
            )],
        )]);

        $this->actingAs($user)->post(route('games.shipping', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->shippingLevel);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(25, $game->state->players[0]->victoryPoints);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(GameActionType::AdvanceShipping, $game->actions()->sole()->type);

        $this->post(route('games.current-turn.restart', $game));
        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->shippingLevel);
        $this->assertSame(4, $game->state->players[0]->resources->coins);
        $this->assertSame(1, $game->state->players[0]->resources->scholars);

        $state = $game->state;
        $state->players[0]->shippingLevel = 1;
        $game->update(['state' => $state]);

        $this->post(route('games.shipping', $game));
        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->shippingLevel);
        $this->assertSame(PendingInteractionType::ChooseShippingBooks, $game->state->pendingInteraction?->type);

        $this->post(route('games.rewards', $game), [
            'book_counts' => [
                'banking' => 0,
                'law' => 2,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertNoContent();

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(2, $game->state->players[0]->resources->books->law);
        $this->assertSame(23, $game->state->players[0]->victoryPoints);
        $this->assertSame(
            2,
            $game->actions()->sole()->payload['reward_book_counts']['law'],
        );
    }

    public function test_terraforming_advancement_grants_rewards_for_each_reached_level(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 15,
            userId: 25,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $advanceDevelopmentTrack = app(AdvanceDevelopmentTrackAction::class);

        $advanceDevelopmentTrack->advanceTerraforming($playerState);
        $this->assertSame(1, $playerState->terraformingLevel);
        $this->assertSame(2, $playerState->resources->books->unassigned);
        $this->assertSame(20, $playerState->victoryPoints);

        $advanceDevelopmentTrack->advanceTerraforming($playerState);
        $this->assertSame(2, $playerState->terraformingLevel);
        $this->assertSame(2, $playerState->resources->books->unassigned);
        $this->assertSame(26, $playerState->victoryPoints);
    }

    public function test_player_can_confirm_terraforming_advancement_choose_books_and_restart_the_turn(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::TrackEngineering->value,
            ),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 2, coins: 10, scholars: 2),
            )],
        )]);

        $this->actingAs($user)->post(route('games.terraforming', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->terraformingLevel);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(5, $game->state->players[0]->resources->coins);
        $this->assertSame(1, $game->state->players[0]->resources->scholars);
        $this->assertSame(2, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(23, $game->state->players[0]->victoryPoints);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(PendingInteractionType::ChooseTerraformingBooks, $game->state->pendingInteraction?->type);
        $this->assertSame(GameActionType::AdvanceTerraforming, $game->actions()->sole()->type);

        $this->post(route('games.rewards', $game), [
            'book_counts' => [
                'banking' => 1,
                'law' => 0,
                'engineering' => 1,
                'medicine' => 0,
            ],
        ])->assertNoContent();

        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(1, $game->state->players[0]->resources->books->engineering);
        $this->assertSame(1, $game->actions()->sole()->payload['reward_book_counts']['banking']);

        $this->post(route('games.current-turn.restart', $game));
        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->terraformingLevel);
        $this->assertSame(2, $game->state->players[0]->resources->tools);
        $this->assertSame(10, $game->state->players[0]->resources->coins);
        $this->assertSame(2, $game->state->players[0]->resources->scholars);
        $this->assertSame(0, $game->state->players[0]->resources->books->banking);

        $state = $game->state;
        $state->players[0]->terraformingLevel = 1;
        $game->update(['state' => $state]);

        $this->post(route('games.terraforming', $game));
        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->terraformingLevel);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(5, $game->state->players[0]->resources->coins);
        $this->assertSame(29, $game->state->players[0]->victoryPoints);
        $this->assertNull($game->state->pendingInteraction);
    }

    public function test_player_cannot_advance_terraforming_without_resources_or_past_the_last_level(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 1, coins: 4, scholars: 1),
            )],
        )]);

        $this->actingAs($user)->post(route('games.terraforming', $game))
            ->assertSessionHasErrors('terraforming');

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->terraformingLevel);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(4, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->actions()->count());

        $state = $game->state;
        $state->players[0]->terraformingLevel = 2;
        $state->players[0]->resources->tools = 1;
        $state->players[0]->resources->coins = 5;
        $state->players[0]->resources->scholars = 1;
        $game->update(['state' => $state]);

        $this->post(route('games.terraforming', $game))
            ->assertSessionHasErrors('terraforming');

        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->terraformingLevel);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(5, $game->state->players[0]->resources->coins);
        $this->assertSame(1, $game->state->players[0]->resources->scholars);
        $this->assertSame(0, $game->actions()->count());
    }

    public function test_brown_player_pays_discounted_terraforming_advancement_cost(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Brown,
                faction: Faction::Blessed,
                homeland: TerrainType::Plains,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 1, coins: 1, scholars: 1),
                terraformingLevel: 1,
            )],
        )]);

        $this->actingAs($user)->post(route('games.terraforming', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->terraformingLevel);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(1, $game->actions()->sole()->payload['coins']);
        $this->assertSame(1, $game->actions()->sole()->payload['tools']);
    }
}
