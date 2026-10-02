<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\PlayerAbilities;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
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

class FactionActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_philosophers_can_choose_a_book_and_restart_the_action(): void
    {
        [$game, $user] = $this->gameForFactionAction(Faction::Philosophers);

        $this->actingAs($user)->post(route('games.faction-action', $game), [
            'discipline' => KnowledgeDiscipline::Engineering->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->books->engineering);
        $this->assertSame(
            [Faction::Philosophers->specialActionId()],
            $game->state->players[0]->usedSpecialActionIds,
        );
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(GameActionType::SpecialAction, $game->actions()->sole()->type);

        $this->post(route('games.faction-action', $game), [
            'discipline' => KnowledgeDiscipline::Law->value,
        ])->assertSessionHasErrors('faction');

        $this->post(route('games.current-turn.restart', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->books->engineering);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
    }

    public function test_psychics_gain_power_without_spending_the_main_action(): void
    {
        [$game, $user] = $this->gameForFactionAction(Faction::Psychics);

        $this->actingAs($user)->post(route('games.faction-action', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(5, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertFalse($game->state->round->hasTakenMainAction);
        $this->assertSame(
            [Faction::Psychics->specialActionId()],
            $game->state->players[0]->usedSpecialActionIds,
        );

        $this->post(route('games.current-turn.restart', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
    }

    /** @return array{Game, User} */
    private function gameForFactionAction(Faction $faction): array
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
                    faction: $faction,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlOne: 5),
                    ),
                )],
            ),
        ]);

        return [$game, $user];
    }
}
