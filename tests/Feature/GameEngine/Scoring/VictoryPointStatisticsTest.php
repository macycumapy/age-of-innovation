<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Scoring;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Actions\ApplyBuildingBonusesAction;
use App\Domain\GameEngine\Board\Actions\BuildWorkshopAction;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Actions\ApplyIncomeAction;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\History\Actions\AppendGameHistoryAction;
use App\Domain\GameEngine\History\Actions\ReplayGameHistoryAction;
use App\Domain\GameEngine\History\Actions\UndoLastGameAction;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Scoring\Actions\ApplyFinalScoringAction;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\Scoring\Enums\VictoryPointSource;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Http\Resources\GameResource;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class VictoryPointStatisticsTest extends TestCase
{
    use RefreshDatabase;

    public function test_history_replay_and_undo_restore_score_sources_and_the_resource_exposes_them(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create(['status' => GameStatus::Active, 'phase' => GamePhase::Actions, 'version' => 0]);
        $participant = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id]);
        $player = $this->player();
        $player->playerId = $participant->id;
        $player->userId = $user->id;
        $player->resources = new PlayerResourcesData(coins: 5, tools: 3);
        $state = new GameStateData(
            turnOrder: [$participant->id],
            players: [$player],
            board: new BoardStateData(hexes: [
                new BoardHexStateData('a', 0, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['b']),
                new BoardHexStateData(
                    'b',
                    1,
                    0,
                    TerrainType::Forest,
                    TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Workshop, $participant->id),
                    adjacentHexIds: ['a']
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions, scoringTileId: RoundScoringTile::WorkshopLaw->value),
        );
        $game->update(['state' => $state, 'active_player_id' => $user->id, 'active_game_player_id' => $participant->id]);
        app(AppendGameHistoryAction::class)->execute($game, $participant, GameActionType::FinishTurn, [], [], 0, 0, createPhaseCheckpoint: true);

        $game = app(BuildWorkshopAction::class)->execute($game, $participant, 'a');
        $expectedSources = ['starting' => 20, 'round_scoring' => 2];
        $this->assertEquals($expectedSources, $game->state->players[0]->victoryPointBreakdown());

        $game = app(ReplayGameHistoryAction::class)->execute($game, $game->actions()->orderBy('sequence')->get());
        $this->assertEquals($expectedSources, $game->state->players[0]->victoryPointSources);
        $this->assertSame(22, $game->state->players[0]->victoryPoints);
        $game->load('players.user')->loadCount('players')->setAttribute('is_joined', true);
        $data = (new GameResource($game))->resolve(Request::create('/'));
        $this->assertEquals($expectedSources, $data['playerBoardStates'][0]['victoryPointSources']);

        $game = app(UndoLastGameAction::class)->execute($game);
        $this->assertSame(['starting' => 20], $game->state->players[0]->victoryPointSources);
        $this->assertSame(20, $game->state->players[0]->victoryPoints);
        $this->assertNull($game->state->board->hexes[0]->building);
    }

    public function test_building_points_are_split_between_simultaneous_sources(): void
    {
        $player = $this->player();
        $player->roundBonus = RoundBonus::RiverWorkshop;
        $player->palaceId = PalaceAbility::Palace12->value;
        $player->competencyIds = [Competency::Competency11->value];
        $hex = new BoardHexStateData('a', 0, 0, TerrainType::Forest, TerrainType::Forest);
        $state = new GameStateData(
            board: new BoardStateData(hexes: [$hex], edgeHexIds: ['a'], riverBankHexIds: ['a']),
            players: [$player],
            round: new RoundStateData(phase: GamePhase::Actions, scoringTileId: RoundScoringTile::WorkshopLaw->value),
        );

        app(ApplyBuildingBonusesAction::class)->execute($state, $player, $hex, BuildingType::Workshop);

        $this->assertSame(['starting' => 20, 'round_scoring' => 2, 'round_bonus' => 2, 'competency' => 3, 'palace' => 2, 'faction' => 2], $player->victoryPointBreakdown());
        $this->assertSame(31, $player->victoryPoints);
        $this->assertSame($player->victoryPoints, array_sum($player->victoryPointSources));
    }

    public function test_income_points_keep_the_competency_innovation_and_knowledge_sources(): void
    {
        $player = $this->player();
        $player->competencyIds = [Competency::Competency02->value];
        $player->inventionIds = [Innovation::University->value];
        $player->knowledge->medicine = 9;
        $state = new GameStateData(players: [$player]);

        app(ApplyIncomeAction::class)->execute($state, $player);
        app(ApplyIncomeAction::class)->execute($state, $player);

        $this->assertSame(['starting' => 20, 'competency' => 6, 'innovation' => 4, 'knowledge_income' => 6], $player->victoryPointBreakdown());
        $this->assertSame(36, $player->victoryPoints);
        $restored = GameStateData::from($state->toArray());
        $this->assertSame($player->victoryPointBreakdown(), $restored->players[0]->victoryPointBreakdown());
    }

    public function test_final_scoring_separates_territory_and_knowledge_points(): void
    {
        $player = $this->player();
        $player->knowledge->banking = 4;
        $state = new GameStateData(players: [$player]);

        app(ApplyFinalScoringAction::class)->execute($state);

        $this->assertSame(['starting' => 20, 'final_objectives' => 18, 'final_knowledge' => 8], $player->victoryPointBreakdown());
        $this->assertSame(46, $player->victoryPoints);
    }

    public function test_old_saves_keep_untracked_points_without_assigning_them_to_a_source(): void
    {
        $serialized = $this->player()->toArray();
        unset($serialized['victoryPointSources']);
        $serialized['victoryPoints'] = 80;
        $player = GamePlayerStateData::from($serialized);
        $player->addVictoryPoints(5, VictoryPointSource::Innovation);
        $player->addVictoryPoints(-2, VictoryPointSource::PowerOffers);

        $this->assertSame(['starting' => 20, 'innovation' => 5, 'power_offers' => -2, 'untracked' => 60], $player->victoryPointBreakdown());
        $this->assertSame(83, array_sum($player->victoryPointBreakdown()));
    }

    private function player(): GamePlayerStateData
    {
        return new GamePlayerStateData(
            playerId: 1,
            userId: null,
            color: PlayerColor::Green,
            faction: Faction::Navigators,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
    }
}
