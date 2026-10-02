<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Economy;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Economy\Enums\PowerAction;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PowerActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_player_can_sacrifice_power_without_ending_the_turn(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $users[0]->id,
            'version' => 7,
        ]);
        $activePlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$activePlayer->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                pendingInteraction: new PendingInteractionData(
                    PendingInteractionType::ChooseTown,
                    $activePlayer->id,
                    [TownTile::Tools->value],
                ),
                players: [
                    new GamePlayerStateData(
                        playerId: $activePlayer->id,
                        userId: $users[0]->id,
                        color: PlayerColor::Green,
                        faction: Faction::Blessed,
                        homeland: TerrainType::Forest,
                        roundBonus: RoundBonus::Coins,
                        resources: new PlayerResourcesData(
                            power: new PowerBowlsStateData(bowlTwo: 5, bowlThree: 1),
                        ),
                    ),
                ],
            ),
        ]);

        $this->actingAs($users[1])
            ->post(route('games.power-sacrifice.store', $game), ['amount' => 1])
            ->assertForbidden();

        $this->actingAs($users[0])
            ->post(route('games.power-sacrifice.store', $game), ['amount' => 3])
            ->assertSessionHasErrors('amount');

        $game->refresh();
        $this->assertSame(5, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(1, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame(0, $game->actions()->count());

        $this->post(route('games.power-sacrifice.store', $game), ['amount' => 2])
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(3, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame($users[0]->id, $game->active_player_id);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame(8, $game->version);

        $action = $game->actions()->sole();
        $this->assertSame(GameActionType::SacrificePower, $action->type);
        $this->assertSame(['amount' => 2], $action->payload);
        $this->assertSame('power_sacrificed', $action->events[0]['type']);
        $this->assertSame(2, $action->events[0]['sacrificed']);
        $this->assertSame(2, $action->events[0]['moved_to_bowl_three']);
    }

    public function test_power_action_can_sacrifice_missing_power_and_apply_the_effect_atomically(): void
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
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                pendingInteraction: new PendingInteractionData(
                    PendingInteractionType::ChooseTown,
                    $player->id,
                    [TownTile::Tools->value],
                ),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlTwo: 4, bowlThree: 2),
                    ),
                )],
            ),
        ]);

        $this->actingAs($user)
            ->post(route('games.power-action', $game), [
                'action' => PowerAction::GainTools->value,
                'sacrifice_amount' => 2,
            ])
            ->assertNoContent();

        $game->refresh();
        $playerState = $game->state->players[0];
        $this->assertSame(0, $playerState->resources->power->bowlTwo);
        $this->assertSame(0, $playerState->resources->power->bowlThree);
        $this->assertSame(4, $playerState->resources->power->bowlOne);
        $this->assertSame(2, $playerState->resources->tools);
        $this->assertContains(PowerAction::GainTools->value, $game->state->round->usedSharedActionIds);
        $this->assertSame(GameActionType::PowerAction, $game->actions()->sole()->type);
        $this->assertSame(2, $game->actions()->sole()->payload['sacrifice_amount']);

        $this->post(route('games.power-action', $game), [
            'action' => PowerAction::GainTools->value,
            'sacrifice_amount' => 0,
        ])->assertSessionHasErrors('action');

        $this->assertSame(1, $game->actions()->count());
    }

    #[DataProvider('illusionistPowerActionProvider')]
    public function test_illusionists_pay_less_for_power_actions_and_gain_victory_points(
        int $playerCount,
        int $expectedVictoryPoints,
    ): void {
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
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Illusionists,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(
                    power: new PowerBowlsStateData(bowlThree: 3),
                ),
            )],
            setupPool: app(GameSetupPoolFactory::class)->create($playerCount),
        )]);

        $this->actingAs($user)->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page
                ->where('game.data.powerActions.2.cost', 3)
                ->where(
                    'game.data.powerActions.2.description',
                    'Потратить 3 силы, чтобы получить 2 инструмента.',
                ),
        );

        $this->post(route('games.power-action', $game), [
            'action' => PowerAction::GainTools->value,
            'sacrifice_amount' => 0,
        ])->assertNoContent();

        $game->refresh();
        $playerState = $game->state->players[0];
        $this->assertSame(0, $playerState->resources->power->bowlThree);
        $this->assertSame(3, $playerState->resources->power->bowlOne);
        $this->assertSame(2, $playerState->resources->tools);
        $this->assertSame(20 + $expectedVictoryPoints, $playerState->victoryPoints);
        $this->assertSame($expectedVictoryPoints, $game->actions()->sole()->payload['victory_points']);
    }

    /** @return iterable<string, array{int, int}> */
    public static function illusionistPowerActionProvider(): iterable
    {
        yield '3 игрока' => [3, 1];
        yield '4 игрока' => [4, 2];
    }

    public function test_power_action_is_not_applied_when_power_cannot_be_sacrificed(): void
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
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlTwo: 2, bowlThree: 2),
                    ),
                )],
            ),
        ]);

        $this->actingAs($user)
            ->post(route('games.power-action', $game), [
                'action' => PowerAction::GainTools->value,
                'sacrifice_amount' => 2,
            ])
            ->assertSessionHasErrors('sacrifice_amount');

        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(2, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->actions()->count());
    }
}
