<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\PlayerAbilities;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
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

class CompetencyActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_competency_seven_gains_power_only_once_and_can_be_restarted(): void
    {
        [$game, $user] = $this->gameForFactionAction(Faction::Blessed);
        $state = $game->state;
        $state->players[0]->competencyIds = [Competency::Competency07->value];
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.competency-action', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(4, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlThree);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(
            [Competency::Competency07->value],
            $game->state->players[0]->usedSpecialActionIds,
        );
        $this->assertSame(Competency::Competency07->value, $game->actions()->sole()->payload['competency']);
        $this->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page
                ->where('game.data.playerBoardStates.0.canUseCompetencyAction', false)
                ->where('game.data.playerBoardStates.0.isCompetencyActionUsed', true),
        );

        $this->post(route('games.competency-action', $game))
            ->assertForbidden();

        $this->post(route('games.current-turn.restart', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(5, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
        $this->assertFalse($game->state->round->hasTakenMainAction);

        $state = $game->state;
        $state->round->hasTakenMainAction = true;
        $game->update(['state' => $state]);

        $this->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page
                ->where('game.data.playerBoardStates.0.canUseCompetencyAction', false)
                ->where('game.data.playerBoardStates.0.isCompetencyActionUsed', false),
        );
        $this->post(route('games.competency-action', $game))->assertForbidden();

        $game->refresh();
        $this->assertSame(5, $game->state->players[0]->resources->power->bowlOne);
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
