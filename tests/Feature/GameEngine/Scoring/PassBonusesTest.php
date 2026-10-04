<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Scoring;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\PlayerAbilities\Data\RoundBonusOfferData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PassBonusesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{BuildingType, int, string|null, string|null}> */
    public static function neutralBuildingPassRewards(): array
    {
        return [
            'palace' => [BuildingType::Palace, 4, 'round_bonus', RoundBonus::PassPalaceUniversity->value],
            'university' => [BuildingType::University, 4, 'round_bonus', RoundBonus::PassPalaceUniversity->value],
            'school' => [BuildingType::School, 3, 'palace', PalaceAbility::Palace07->value],
            'guild' => [BuildingType::Guild, 2, 'innovation', Innovation::TradeRoutes->value],
            'workshop has no pass reward' => [BuildingType::Workshop, 0, null, null],
        ];
    }

    #[DataProvider('neutralBuildingPassRewards')]
    public function test_pass_rewards_count_owned_regular_and_neutral_buildings_only(
        BuildingType $buildingType,
        int $pointsPerBuilding,
        ?string $source,
        ?string $sourceId,
    ): void {
        [$game, $user] = $this->gameForPassing();
        $state = $game->state;
        $player = $state->players[0];
        $player->roundBonus = RoundBonus::PassPalaceUniversity;
        $player->palaceId = PalaceAbility::Palace07->value;
        $player->inventionIds = [Innovation::TradeRoutes->value];
        $buildings = [
            new BuildingStateData($buildingType, $player->playerId),
            new BuildingStateData($buildingType, $player->playerId, isNeutral: true),
            new BuildingStateData($buildingType, $state->players[1]->playerId),
            new BuildingStateData($buildingType, $state->players[1]->playerId, isNeutral: true),
            null,
        ];
        $state->board = new BoardStateData(hexes: array_map(
            static fn (?BuildingStateData $building, int $index): BoardHexStateData => new BoardHexStateData(
                id: "{$index}:0",
                q: $index,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: $building,
            ),
            $buildings,
            array_keys($buildings),
        ));
        $pointsBefore = $player->victoryPoints;
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.pass', $game))->assertNoContent();

        $game->refresh();
        $expectedPoints = $pointsPerBuilding * 2;
        $this->assertSame($pointsBefore + $expectedPoints, $game->state->players[0]->victoryPoints);
        $action = $game->actions()->sole();
        $this->assertSame($expectedPoints, $action->payload['victory_points']);
        $this->assertSame(
            $expectedPoints === 0 ? [] : [['id' => $sourceId, 'points' => $expectedPoints, 'source' => $source]],
            $action->payload['scoring_sources'],
        );
    }

    public function test_pass_awards_victory_points_from_all_pass_bonus_sources(): void
    {
        [$game, $firstUser] = $this->gameForPassing();
        $state = $game->state;
        $player = $state->players[0];
        $player->roundBonus = RoundBonus::PassPalaceUniversity;
        $player->competencyIds = [Competency::Competency08->value, Competency::Competency12->value];
        $player->palaceId = PalaceAbility::Palace07->value;
        $player->inventionIds = [Innovation::TradeRoutes->value];
        $player->townTileIds = ['town_01', 'town_02'];
        $player->knowledge = new KnowledgeStateData(banking: 5, law: 2, engineering: 4, medicine: 3);
        $buildingTypes = [
            BuildingType::Palace,
            BuildingType::University,
            BuildingType::School,
            BuildingType::School,
            BuildingType::Guild,
            BuildingType::Guild,
            BuildingType::Guild,
        ];
        $state->board = new BoardStateData(hexes: array_map(
            static fn (BuildingType $buildingType, int $index): BoardHexStateData => new BoardHexStateData(
                id: "{$index}:0",
                q: $index,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData($buildingType, $player->playerId),
            ),
            $buildingTypes,
            array_keys($buildingTypes),
        ));
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ])->assertNoContent()->assertSessionHasNoErrors();

        $game->refresh();
        $this->assertSame(46, $game->state->players[0]->victoryPoints);
        $action = $game->actions()->sole();
        $this->assertSame(26, $action->payload['victory_points']);
        $this->assertCount(5, $action->payload['scoring_sources']);
        $this->assertSame([8, 4, 2, 6, 6], array_column($action->payload['scoring_sources'], 'points'));

    }

    /** @return array{Game, User, User} */
    private function gameForPassing(): array
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
        $setupPool = (new GameSetupPoolFactory())->createFromSeed(2, 'pass-test');
        $setupPool->roundScoringTiles[0] = RoundScoringTile::WorkshopLaw;
        $setupPool->availableRoundBonuses = [
            new RoundBonusOfferData(RoundBonus::RiverWorkshop, 2),
            new RoundBonusOfferData(RoundBonus::BuildGuild),
            new RoundBonusOfferData(RoundBonus::Coins),
        ];
        $game->update(['state' => new GameStateData(
            turnOrder: [$firstPlayer->id, $secondPlayer->id],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: $setupPool->roundScoringTiles[0]->value,
            ),
            players: [
                new GamePlayerStateData(
                    playerId: $firstPlayer->id,
                    userId: $firstUser->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Knowledge,
                ),
                new GamePlayerStateData(
                    playerId: $secondPlayer->id,
                    userId: $secondUser->id,
                    color: PlayerColor::Blue,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Swamp,
                    roundBonus: RoundBonus::PowerCoins,
                ),
            ],
            setupPool: $setupPool,
        )]);

        return [$game, $firstUser, $secondUser];
    }
}
