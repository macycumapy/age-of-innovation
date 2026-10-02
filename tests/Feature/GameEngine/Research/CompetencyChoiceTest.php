<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Research;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Actions\CreateBuildingFollowUpInteractionAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
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
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompetencyChoiceTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('competencyBuildingUpgradeProvider')]
    public function test_player_chooses_a_competency_after_building_a_school_or_university(
        BuildingType $sourceBuilding,
        BuildingType $targetBuilding,
        int $toolCost,
        int $coinCost,
        int $expectedVictoryPoints,
    ): void {
        $user = User::factory()->create();
        $neighborUser = User::factory()->create();
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
        $neighbor = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $neighborUser->id,
            'seat' => 2,
        ]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id, $neighbor->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData($sourceBuilding, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['0:0'],
                    building: new BuildingStateData(BuildingType::Workshop, $neighbor->id),
                ),
            ]),
            round: new RoundStateData(
                number: 6,
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::KnowledgeMedicine->value,
                additionalScoringTileId: FinalRoundScoringTile::School->value,
            ),
            players: [
                new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Philosophers,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(coins: $coinCost, tools: $toolCost),
                ),
                new GamePlayerStateData(
                    playerId: $neighbor->id,
                    userId: $neighborUser->id,
                    color: PlayerColor::Red,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlTwo: 2),
                    ),
                ),
            ],
            availableCompetencyIds: [Competency::Competency04->value],
        )]);

        $this->actingAs($user)->post(route('games.building-upgrade', $game), [
            'hex_id' => '0:0',
            'target' => $targetBuilding->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseCompetency, $game->state->pendingInteraction?->type);
        $this->assertSame([
            'reason' => 'building',
            'builtHexId' => '0:0',
            'buildingType' => $targetBuilding->value,
        ], $game->state->pendingInteraction?->context);
        $this->assertSame([Competency::Competency04->value], $game->state->pendingInteraction?->optionIds);
        $this->assertSame($user->id, $game->active_player_id);

        $this->post(route('games.rewards', $game), [
            'competency_id' => Competency::Competency04->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertContains(Competency::Competency04->value, $game->state->players[0]->competencyIds);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
        $this->assertSame(2, $game->state->players[0]->resources->coins);
        $this->assertSame($expectedVictoryPoints, $game->state->players[0]->victoryPoints);
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(PendingInteractionType::PowerOffer, $game->state->pendingInteraction?->type);
        $this->assertSame($neighborUser->id, $game->active_player_id);
        $this->assertSame([
            GameActionType::UpgradeBuilding,
            GameActionType::ChooseCompetency,
        ], $game->actions()->orderBy('sequence')->pluck('type')->all());
        $this->assertSame(3, $game->actions()->latest('sequence')->firstOrFail()->payload['victory_points']);
    }

    /** @return array<string, array{BuildingType, BuildingType, int, int, int}> */
    public static function competencyBuildingUpgradeProvider(): array
    {
        return [
            'school' => [BuildingType::Guild, BuildingType::School, 3, 5, 32],
            'university' => [BuildingType::School, BuildingType::University, 5, 8, 28],
        ];
    }

    public function test_competency_five_starts_terraforming_with_two_free_spades(): void
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
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData(BuildingType::School, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['0:0'],
                ),
            ]),
            round: new RoundStateData(phase: GamePhase::Actions, hasTakenMainAction: true),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
            )],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseCompetency,
                $player->id,
                [Competency::Competency05->value],
                [
                    'reason' => 'building',
                    'builtHexId' => '0:0',
                    'buildingType' => BuildingType::School->value,
                ],
            ),
            availableCompetencyIds: [Competency::Competency05->value],
        )]);

        $this->actingAs($user)->post(route('games.rewards', $game), [
            'competency_id' => Competency::Competency05->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->unassignedSpades);
        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);
        $this->assertSame(2, $game->state->pendingInteraction?->context['remainingSpades']);
        $this->assertSame(TerrainType::Forest->value, $game->state->pendingInteraction?->context['targetTerrain']);
        $this->assertContains('1:0', $game->state->pendingInteraction?->optionIds);
        $this->assertSame($user->id, $game->active_player_id);
    }

    #[DataProvider('neutralInnovationBuildingTerrainProvider')]
    public function test_competency_ten_places_a_neutral_tower_after_any_required_terraforming(
        TerrainType $targetTerrain,
        int $expectedToolCost,
    ): void {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData(BuildingType::School, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: $targetTerrain,
                    terrain: $targetTerrain,
                    adjacentHexIds: ['0:0'],
                ),
            ]),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 3),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableCompetencyIds: [Competency::Competency10->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseCompetency,
                $player->id,
                [Competency::Competency10->value],
                ['reason' => 'building', 'builtHexId' => '0:0', 'buildingType' => BuildingType::School->value],
            ),
        )]);

        $this->actingAs($user)->post(route('games.rewards', $game), [
            'competency_id' => Competency::Competency10->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::PlaceNeutralBuilding, $game->state->pendingInteraction?->type);
        $this->assertSame(BuildingType::Tower->value, $game->state->pendingInteraction?->context['buildingType']);
        $this->assertSame(['1:0'], $game->state->pendingInteraction?->optionIds);

        $this->post(route('games.innovation.neutral-building', $game), ['hex_id' => '1:0'])
            ->assertNoContent();

        $game->refresh();
        $tower = $game->state->board->hexes[1];
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(3 - $expectedToolCost, $game->state->players[0]->resources->tools);
        $this->assertSame(TerrainType::Forest, $tower->terrain);
        $this->assertSame(BuildingType::Tower, $tower->building?->type);
        $this->assertTrue($tower->building?->isNeutral);
        $this->assertSame('1:0', $game->actions()->sole()->payload['neutral_building']['hex_id']);
        $this->assertSame(BuildingType::Tower->value, $game->actions()->sole()->payload['neutral_building']['type']);
    }

    public function test_neutral_university_does_not_grant_a_competency(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 10,
            userId: 20,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $state = new GameStateData(
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(
                        BuildingType::University,
                        $playerState->playerId,
                        isNeutral: true,
                    ),
                ),
            ]),
            players: [$playerState],
            availableCompetencyIds: [Competency::Competency04->value],
        );

        $nextActivePlayerId = app(CreateBuildingFollowUpInteractionAction::class)->execute(
            $state,
            $playerState,
            '0:0',
            BuildingType::University,
        );

        $this->assertNull($state->pendingInteraction);
        $this->assertSame($playerState->playerId, $nextActivePlayerId);
        $this->assertSame([], $playerState->competencyIds);
    }

    /** @return array<string, array{TerrainType, int}> */
    public static function neutralInnovationBuildingTerrainProvider(): array
    {
        return [
            'without terraforming' => [TerrainType::Forest, 0],
            'with terraforming' => [TerrainType::Mountain, 3],
        ];
    }
}
