<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Economy;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Actions\ApplyPowerOfferDecisionAction;
use App\Domain\GameEngine\Economy\Actions\CreatePowerOffersAfterBuildingAction;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Actions\CreateBuildingFollowUpInteractionAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
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
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PowerOffersTest extends TestCase
{
    use RefreshDatabase;

    public function test_restart_after_neutral_tower_restores_competency_choice_without_reverting_accepted_power(): void
    {
        $builderUser = User::factory()->create();
        $neighborUser = User::factory()->create();
        $game = Game::factory()->create(['status' => GameStatus::Active, 'phase' => GamePhase::Actions]);
        $builder = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $builderUser->id, 'seat' => 1]);
        $neighbor = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $neighborUser->id, 'seat' => 2]);
        $state = new GameStateData(
            turnOrder: [$builder->id, $neighbor->id],
            players: array_map(static fn (GamePlayer $player): GamePlayerStateData => new GamePlayerStateData(
                playerId: $player->id,
                userId: $player->user_id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 3, power: new PowerBowlsStateData(bowlOne: 8)),
            ), [$builder, $neighbor]),
            round: new RoundStateData(phase: GamePhase::Actions, turnStartVersion: 0, hasTakenMainAction: true),
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0', '-1:0'],
                    building: new BuildingStateData(BuildingType::School, $builder->id)
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['0:0'],
                    building: new BuildingStateData(BuildingType::Guild, $neighbor->id)
                ),
                new BoardHexStateData(
                    id: '-1:0',
                    q: -1,
                    r: 0,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    adjacentHexIds: ['0:0']
                ),
            ]),
            availableCompetencyIds: [Competency::Competency10->value],
        );
        $nextPlayerId = app(CreateBuildingFollowUpInteractionAction::class)->execute($state, $state->players[0], '0:0', BuildingType::School);
        $game->update(['state' => $state, 'active_game_player_id' => $nextPlayerId, 'version' => 1]);

        $this->actingAs($neighborUser)->post(route('games.power-offer', $game), ['accept' => true])->assertNoContent();
        $game->refresh();
        $checkpoint = $game->state->turnStartSnapshot;
        $this->assertIsArray($checkpoint);
        $this->assertSame(PendingInteractionType::ChooseCompetency, $game->state->pendingInteraction?->type);
        $this->actingAs($builderUser)->get(route('games.show', $game))
            ->assertInertia(fn (Assert $page) => $page->where('game.data.canRestartCurrentTurn', false));
        $this->actingAs($builderUser)->post(route('games.rewards', $game), ['competency_id' => Competency::Competency10->value])->assertNoContent();
        $this->post(route('games.innovation.neutral-building', $game), ['hex_id' => '-1:0'])->assertNoContent();
        $game->refresh();
        $this->assertSame(BuildingType::Tower, $game->state->board->hexes[2]->building?->type);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->get(route('games.show', $game))->assertInertia(fn (Assert $page) => $page->where('game.data.canRestartCurrentTurn', true));

        $this->post(route('games.current-turn.restart', $game))->assertNoContent();
        $game->refresh();
        $restoredCheckpoint = GameStateData::from($checkpoint);
        $restoredCheckpoint->turnStartSnapshot = $checkpoint;
        $this->assertEquals($restoredCheckpoint->toArray(), $game->state->toArray());
        $this->assertSame(BuildingType::School, $game->state->board->hexes[0]->building?->type);
        $this->assertNull($game->state->board->hexes[2]->building);
        $this->assertSame(19, $game->state->players[1]->victoryPoints);
        $this->assertSame([GameActionType::AcceptPower], $game->actions()->pluck('type')->all());
        $this->get(route('games.show', $game))
            ->assertInertia(fn (Assert $page) => $page->where('game.data.canRestartCurrentTurn', false));

        $this->post(route('games.rewards', $game), ['competency_id' => Competency::Competency10->value])->assertNoContent();
        $this->post(route('games.innovation.neutral-building', $game), ['skip' => 1])->assertNoContent();
        $this->post(route('games.current-turn.restart', $game))->assertNoContent();
        $this->post(route('games.rewards', $game), ['competency_id' => Competency::Competency10->value])->assertNoContent();
        $this->post(route('games.innovation.neutral-building', $game), ['skip' => 1])->assertNoContent();
        $this->post(route('games.current-turn.finish', $game))->assertNoContent();
    }

    #[DataProvider('buildingChoiceAfterPowerProvider')]
    public function test_all_neighbors_resolve_power_before_building_tile_choice(BuildingType $buildingType, bool $accept): void
    {
        $players = array_map(static fn (int $id): GamePlayerStateData => new GamePlayerStateData(
            playerId: $id,
            userId: $id,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(power: new PowerBowlsStateData(bowlOne: 8)),
        ), [1, 2, 3]);
        $state = new GameStateData(
            turnOrder: [1, 2, 3],
            players: $players,
            round: new RoundStateData(phase: GamePhase::Actions),
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0', '0:1'],
                    building: new BuildingStateData($buildingType, 1),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['0:0'],
                    building: new BuildingStateData(BuildingType::Workshop, 2),
                ),
                new BoardHexStateData(
                    id: '0:1',
                    q: 0,
                    r: 1,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['0:0'],
                    building: new BuildingStateData(BuildingType::Workshop, 3),
                ),
            ]),
            availablePalaceIds: [PalaceAbility::Palace12->value],
            availableCompetencyIds: [Competency::Competency05->value],
        );

        $this->assertSame(2, app(CreateBuildingFollowUpInteractionAction::class)->execute($state, $players[0], '0:0', $buildingType));
        $this->assertSame(PendingInteractionType::PowerOffer, $state->pendingInteraction?->type);
        $this->assertSame(3, app(ApplyPowerOfferDecisionAction::class)->execute($state, 2, $accept)['nextActivePlayerId']);
        $this->assertSame(PendingInteractionType::PowerOffer, $state->pendingInteraction?->type);
        $this->assertSame(1, app(ApplyPowerOfferDecisionAction::class)->execute($state, 3, $accept)['nextActivePlayerId']);
        $this->assertSame(
            $buildingType === BuildingType::Palace ? PendingInteractionType::ChoosePalace : PendingInteractionType::ChooseCompetency,
            $state->pendingInteraction?->type,
        );
        $this->assertSame('0:0', $state->pendingInteraction?->context['builtHexId']);
        $this->assertSame($accept ? 1 : 0, $players[1]->resources->power->bowlTwo);
        $this->assertSame($accept ? 1 : 0, $players[2]->resources->power->bowlTwo);

        if ($buildingType === BuildingType::Palace) {
            app(\App\Domain\GameEngine\PlayerAbilities\Actions\ApplyChoosePalaceAction::class)->execute($state, $players[0], PalaceAbility::Palace12);
        } else {
            app(\App\Domain\GameEngine\Research\Actions\ApplyChooseCompetencyAction::class)->execute($state, $players[0], Competency::Competency05);
        }

        $this->assertNull($state->pendingInteraction);
    }

    /** @return array<string, array{BuildingType, bool}> */
    public static function buildingChoiceAfterPowerProvider(): array
    {
        return [
            'school accepted' => [BuildingType::School, true],
            'school declined' => [BuildingType::School, false],
            'university accepted' => [BuildingType::University, true],
            'university declined' => [BuildingType::University, false],
            'palace accepted' => [BuildingType::Palace, true],
            'palace declined' => [BuildingType::Palace, false],
        ];
    }

    public function test_power_offer_sums_all_adjacent_buildings_with_annexes_before_limiting_received_power(): void
    {
        $builder = new GamePlayerStateData(
            playerId: 1,
            userId: 11,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $neighbor = new GamePlayerStateData(
            playerId: 2,
            userId: 22,
            color: PlayerColor::Blue,
            faction: Faction::Blessed,
            homeland: TerrainType::Mountain,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(
                power: new PowerBowlsStateData(bowlOne: 4),
            ),
        );
        $state = new GameStateData(
            turnOrder: [1, 2],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '8:4',
                    q: 8,
                    r: 4,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['9:4', '8:5'],
                    building: new BuildingStateData(BuildingType::School, 1),
                ),
                new BoardHexStateData(
                    id: '9:4',
                    q: 9,
                    r: 4,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    building: new BuildingStateData(BuildingType::University, 2, hasAnnex: true),
                ),
                new BoardHexStateData(
                    id: '8:5',
                    q: 8,
                    r: 5,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    building: new BuildingStateData(BuildingType::School, 2, hasAnnex: true),
                ),
            ]),
            players: [$builder, $neighbor],
        );

        $nextActivePlayerId = app(CreatePowerOffersAfterBuildingAction::class)->execute($state, 1, '8:4');

        $this->assertSame(2, $nextActivePlayerId);
        $this->assertSame(PendingInteractionType::PowerOffer, $state->pendingInteraction?->type);
        $this->assertSame(7, $state->pendingInteraction?->context['powerAmount']);
    }

    public function test_power_is_not_offered_to_players_who_passed_in_the_final_round(): void
    {
        $builder = new GamePlayerStateData(
            playerId: 1,
            userId: 11,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
        );
        $finishedNeighbor = new GamePlayerStateData(
            playerId: 2,
            userId: 22,
            color: PlayerColor::Blue,
            faction: Faction::Blessed,
            homeland: TerrainType::Mountain,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(
                power: new PowerBowlsStateData(bowlOne: 4),
            ),
        );
        $state = new GameStateData(
            turnOrder: [1, 2],
            passedPlayerIds: [2],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '8:4',
                    q: 8,
                    r: 4,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['9:4'],
                    building: new BuildingStateData(BuildingType::Workshop, 1),
                ),
                new BoardHexStateData(
                    id: '9:4',
                    q: 9,
                    r: 4,
                    initialTerrain: TerrainType::Mountain,
                    terrain: TerrainType::Mountain,
                    building: new BuildingStateData(BuildingType::Workshop, 2),
                ),
            ]),
            round: new RoundStateData(number: 6),
            players: [$builder, $finishedNeighbor],
        );

        $nextActivePlayerId = app(CreatePowerOffersAfterBuildingAction::class)->execute($state, 1, '8:4');

        $this->assertNull($nextActivePlayerId);
        $this->assertNull($state->pendingInteraction);
    }

    public function test_neighbors_resolve_power_offers_in_turn_order_after_a_workshop_is_built(): void
    {
        $builderUser = User::factory()->create();
        $firstNeighborUser = User::factory()->create();
        $secondNeighborUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $builderUser->id,
        ]);
        $builder = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $builderUser->id,
            'seat' => 1,
        ]);
        $firstNeighbor = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $firstNeighborUser->id,
            'seat' => 2,
        ]);
        $secondNeighbor = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $secondNeighborUser->id,
            'seat' => 3,
        ]);
        $targetHex = new BoardHexStateData(
            id: '0:0',
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Mountain,
            terrain: TerrainType::Mountain,
            adjacentHexIds: ['1:0', '0:1', '-1:1'],
        );
        $firstWorkshopHex = new BoardHexStateData(
            id: '1:0',
            q: 1,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            building: new BuildingStateData(BuildingType::Workshop, $firstNeighbor->id),
        );
        $firstGuildHex = new BoardHexStateData(
            id: '0:1',
            q: 0,
            r: 1,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            building: new BuildingStateData(BuildingType::Guild, $firstNeighbor->id),
        );
        $secondUniversityHex = new BoardHexStateData(
            id: '-1:1',
            q: -1,
            r: 1,
            initialTerrain: TerrainType::Desert,
            terrain: TerrainType::Desert,
            building: new BuildingStateData(BuildingType::University, $secondNeighbor->id),
        );
        $game->update(['state' => new GameStateData(
            turnOrder: [$builder->id, $firstNeighbor->id, $secondNeighbor->id],
            board: new BoardStateData(
                hexes: [
                    $targetHex,
                    $firstWorkshopHex,
                    $firstGuildHex,
                    $secondUniversityHex,
                ],
                riverBankHexIds: ['0:0'],
                edgeHexIds: ['0:0'],
            ),
            round: new RoundStateData(
                number: 6,
                phase: GamePhase::Actions,
                scoringTileId: RoundScoringTile::WorkshopLaw->value,
                additionalScoringTileId: FinalRoundScoringTile::EdgeWorkshop->value,
                turnStartVersion: 0,
            ),
            players: [
                new GamePlayerStateData(
                    playerId: $builder->id,
                    userId: $builderUser->id,
                    color: PlayerColor::Green,
                    faction: Faction::Navigators,
                    homeland: TerrainType::Mountain,
                    roundBonus: RoundBonus::RiverWorkshop,
                    resources: new PlayerResourcesData(
                        coins: 2,
                        tools: 1,
                        power: new PowerBowlsStateData(bowlThree: 1),
                    ),
                    palaceId: PalaceAbility::Palace12->value,
                    competencyIds: [Competency::Competency11->value],
                    inventionIds: [Innovation::TradeRoutes->value],
                ),
                new GamePlayerStateData(
                    playerId: $firstNeighbor->id,
                    userId: $firstNeighborUser->id,
                    color: PlayerColor::Blue,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlOne: 1, bowlTwo: 3),
                    ),
                ),
                new GamePlayerStateData(
                    playerId: $secondNeighbor->id,
                    userId: $secondNeighborUser->id,
                    color: PlayerColor::Red,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Desert,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlTwo: 3),
                    ),
                ),
            ],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::BuildWorkshopAfterTerraforming,
                $builder->id,
                ['0:0'],
            ),
        )]);

        $this->actingAs($builderUser)->post(route('games.terraform-workshop', $game), [
            'build' => true,
            'hex_id' => '0:0',
        ]);
        $game->refresh();
        $this->assertSame(34, $game->state->players[0]->victoryPoints);
        $this->assertSame(1, $game->state->players[0]->resources->coins);
        $this->assertSame(14, $game->actions()->first()?->payload['victory_points']);
        $this->assertSame(1, $game->actions()->first()?->payload['bonus_coins']);
        $this->assertCount(6, $game->actions()->first()?->payload['scoring_sources']);
        $this->assertSame($firstNeighborUser->id, $game->active_player_id);
        $this->assertSame(PendingInteractionType::PowerOffer, $game->state->pendingInteraction?->type);
        $this->assertSame(3, $game->state->pendingInteraction?->context['powerAmount']);

        $this->actingAs($firstNeighborUser)->post(route('games.power-offer', $game), ['accept' => true]);
        $game->refresh();
        $this->assertSame(0, $game->state->players[1]->resources->power->bowlOne);
        $this->assertSame(2, $game->state->players[1]->resources->power->bowlTwo);
        $this->assertSame(2, $game->state->players[1]->resources->power->bowlThree);
        $this->assertSame(18, $game->state->players[1]->victoryPoints);
        $this->assertSame($secondNeighborUser->id, $game->active_player_id);
        $this->assertSame(3, $game->state->pendingInteraction?->context['powerAmount']);

        $this->actingAs($builderUser)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where('game.data.canUndoLastAction', false),
            );

        $this->actingAs($secondNeighborUser)->post(route('games.power-offer', $game), ['accept' => false]);
        $game->refresh();
        $this->assertSame(3, $game->state->players[2]->resources->power->bowlTwo);
        $this->assertSame(20, $game->state->players[2]->victoryPoints);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame($builderUser->id, $game->active_player_id);
        $this->assertFalse($game->state->round->isCurrentTurnIrrevocable);
        $this->assertSame($game->version, $game->state->round->turnStartVersion);
        $this->assertNull($game->state->turnStartSnapshot);
        $this->assertSame([
            GameActionType::TerraformAndBuild,
            GameActionType::AcceptPower,
            GameActionType::DeclinePower,
        ], $game->actions()->orderBy('sequence')->pluck('type')->all());

        $this->actingAs($builderUser)
            ->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.canRestartCurrentTurn', false)
                    ->where('game.data.canUndoLastAction', false),
            );
        $this->post(route('games.current-turn.restart', $game))->assertForbidden();
        $coinsAtPowerCheckpoint = $game->state->players[0]->resources->coins;
        $this->post(route('games.resource-exchange', $game), [
            'exchanges' => $this->resourceExchanges(powerToCoin: 1),
        ])->assertNoContent();
        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame($coinsAtPowerCheckpoint + 1, $game->state->players[0]->resources->coins);
        $this->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page->where('game.data.canRestartCurrentTurn', true),
        );

        $this->post(route('games.current-turn.restart', $game))->assertNoContent();
        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame($coinsAtPowerCheckpoint, $game->state->players[0]->resources->coins);
        $this->assertSame([
            GameActionType::TerraformAndBuild,
            GameActionType::AcceptPower,
            GameActionType::DeclinePower,
        ], $game->actions()->orderBy('sequence')->pluck('type')->all());

        $this->post(route('games.current-turn.finish', $game))
            ->assertNoContent();
        $game->refresh();
        $this->assertFalse($game->state->round->isCurrentTurnIrrevocable);
        $this->assertSame($firstNeighborUser->id, $game->active_player_id);
    }

    /**
     * @param array<string, int> $powerToBook
     * @param array<string, int> $bookToCoin
     * @return array<string, int|array<string, int>>
     */
    private function resourceExchanges(
        int $powerToScholar = 0,
        int $powerToTool = 0,
        int $powerToCoin = 0,
        int $scholarToTool = 0,
        int $toolToCoin = 0,
        array $powerToBook = [],
        array $bookToCoin = [],
    ): array {
        $emptyBooks = ['banking' => 0, 'law' => 0, 'engineering' => 0, 'medicine' => 0];

        return [
            'power_to_scholar' => $powerToScholar,
            'power_to_tool' => $powerToTool,
            'power_to_coin' => $powerToCoin,
            'scholar_to_tool' => $scholarToTool,
            'tool_to_coin' => $toolToCoin,
            'power_to_book' => array_replace($emptyBooks, $powerToBook),
            'book_to_coin' => array_replace($emptyBooks, $bookToCoin),
        ];
    }
}
