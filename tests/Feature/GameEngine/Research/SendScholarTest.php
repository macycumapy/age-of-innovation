<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Research;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Scoring\Enums\FinalRoundScoringTile;
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

class SendScholarTest extends TestCase
{
    use RefreshDatabase;

    public function test_scholars_advance_disciplines_and_only_placed_scholars_leave_the_pool(): void
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $firstUser->id,
        ]);
        $firstPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $firstUser->id,
            'seat' => 1,
        ]);
        $secondPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$firstPlayer->id, $secondPlayer->id],
            round: new RoundStateData(
                number: 6,
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::KnowledgeMedicine->value,
                additionalScoringTileId: FinalRoundScoringTile::School->value,
            ),
            players: [
                new GamePlayerStateData(
                    playerId: $firstPlayer->id,
                    userId: $firstUser->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::SendScholar,
                    competencyIds: [Competency::Competency09->value],
                    resources: new PlayerResourcesData(scholars: 2),
                ),
                new GamePlayerStateData(
                    playerId: $secondPlayer->id,
                    userId: $secondUser->id,
                    color: PlayerColor::Red,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::SendScholar,
                    resources: new PlayerResourcesData(scholars: 1),
                ),
            ],
        )]);

        $this->assertSame(7, $game->state->players[0]->scholarPoolSize);
        $this->assertSame(7, $game->state->players[1]->scholarPoolSize);
        $firstPlayerVictoryPoints = $game->state->players[0]->victoryPoints;
        $secondPlayerVictoryPoints = $game->state->players[1]->victoryPoints;

        $this->actingAs($firstUser)->post(route('games.scholar', $game), [
            'discipline' => 'law',
            'place' => true,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(3, $game->state->players[0]->knowledge->law);
        $this->assertSame(1, $game->state->players[0]->resources->scholars);
        $this->assertSame(6, $game->state->players[0]->scholarPoolSize);
        $this->assertSame(['law'], $game->state->players[0]->scholarDisciplineIds);
        $this->assertSame([0], $game->state->players[0]->scholarSlotIndexes);
        $this->assertSame($firstPlayerVictoryPoints + 7, $game->state->players[0]->victoryPoints);
        $this->assertSame(7, $game->actions()->latest('sequence')->firstOrFail()->payload['victory_points']);
        $this->assertSame(0, $game->actions()->latest('sequence')->firstOrFail()->payload['slot_index']);

        $state = $game->state;
        $state->round->hasTakenMainAction = false;
        $state->turnStartSnapshot = null;
        $state->round->turnStartVersion = null;
        $game->update(['active_player_id' => $secondUser->id, 'state' => $state]);

        $this->actingAs($secondUser)->post(route('games.scholar', $game), [
            'discipline' => 'law',
            'place' => true,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(2, $game->state->players[1]->knowledge->law);
        $this->assertSame(0, $game->state->players[1]->resources->scholars);
        $this->assertSame(6, $game->state->players[1]->scholarPoolSize);
        $this->assertSame([1], $game->state->players[1]->scholarSlotIndexes);
        $this->assertSame($secondPlayerVictoryPoints + 4, $game->state->players[1]->victoryPoints);
        $this->assertSame(4, $game->actions()->latest('sequence')->firstOrFail()->payload['victory_points']);
        $this->assertSame(1, $game->actions()->latest('sequence')->firstOrFail()->payload['slot_index']);

        $state = $game->state;
        $state->round->hasTakenMainAction = false;
        $state->turnStartSnapshot = null;
        $state->round->turnStartVersion = null;
        $game->update(['active_player_id' => $firstUser->id, 'state' => $state]);

        $this->actingAs($firstUser)->post(route('games.scholar', $game), [
            'discipline' => 'medicine',
            'place' => false,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->knowledge->medicine);
        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(6, $game->state->players[0]->scholarPoolSize);
        $this->assertSame(['law'], $game->state->players[0]->scholarDisciplineIds);
        $this->assertSame($firstPlayerVictoryPoints + 12, $game->state->players[0]->victoryPoints);
        $this->assertSame(5, $game->actions()->latest('sequence')->firstOrFail()->payload['victory_points']);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame([
            GameActionType::SendScholar,
            GameActionType::SendScholar,
            GameActionType::SendScholar,
        ], $game->actions()->orderBy('sequence')->pluck('type')->all());
    }
}
