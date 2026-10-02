<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Research;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Innovation;
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

class InnovationActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_professor_innovation_action_grants_a_scholar_and_victory_points(): void
    {
        [$game, $user] = $this->gameForFactionAction(Faction::Blessed);
        $state = $game->state;
        $state->players[0]->inventionIds = [Innovation::Professor->value];
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.innovation-action', $game), [
            'innovation' => Innovation::Professor->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->scholars);
        $this->assertSame(23, $game->state->players[0]->victoryPoints);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertContains(
            Innovation::Professor->specialActionId(),
            $game->state->players[0]->usedSpecialActionIds,
        );
        $this->assertSame([
            'spades' => 0,
            'scholars' => 1,
            'victoryPoints' => 3,
        ], $game->actions()->where('type', GameActionType::SpecialAction)->sole()->payload['reward']);
        $this->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page
                ->where('game.data.playerBoardStates.0.availableInnovationActionIds', [])
                ->where('game.data.playerBoardStates.0.usedInnovationActionIds', [Innovation::Professor->value]),
        );
    }

    public function test_deus_ex_machina_innovation_action_records_its_spade_reward(): void
    {
        [$game, $user] = $this->gameForFactionAction(Faction::Blessed);
        $state = $game->state;
        $state->players[0]->inventionIds = [Innovation::DeusExMachina->value];
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.innovation-action', $game), [
            'innovation' => Innovation::DeusExMachina->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->unassignedSpades);
        $this->assertSame([
            'spades' => 1,
            'scholars' => 0,
            'victoryPoints' => 0,
        ], $game->actions()->where('type', GameActionType::SpecialAction)->sole()->payload['reward']);
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
