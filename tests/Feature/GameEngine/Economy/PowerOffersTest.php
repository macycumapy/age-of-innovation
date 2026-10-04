<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Economy;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Actions\CreatePowerOffersAfterBuildingAction;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
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
use Tests\TestCase;

class PowerOffersTest extends TestCase
{
    use RefreshDatabase;

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
