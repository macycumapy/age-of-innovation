<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\History;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\History\Actions\ReplayGameHistoryAction;
use App\Domain\GameEngine\History\Actions\UndoLastGameAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ReplayGameHistoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_replay_uses_game_player_id_to_distinguish_bots(): void
    {
        Queue::fake();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'version' => 0,
        ]);
        $firstBot = GamePlayer::factory()->bot()->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $secondBot = GamePlayer::factory()->bot()->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 2,
        ]);
        $state = new GameStateData(
            turnOrder: [$firstBot->id, $secondBot->id],
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: 'target',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Wasteland,
                terrain: TerrainType::Wasteland,
            )]),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [
                new GamePlayerStateData(
                    playerId: $firstBot->id,
                    userId: null,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                ),
                new GamePlayerStateData(
                    playerId: $secondBot->id,
                    userId: null,
                    color: PlayerColor::Red,
                    faction: Faction::Inventors,
                    homeland: TerrainType::Wasteland,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        coins: 5,
                        tools: 2,
                        power: new PowerBowlsStateData(bowlThree: 1),
                    ),
                ),
            ],
        );
        $game->update(['state' => $state]);

        $game->actions()->create([
            'sequence' => 1,
            'player_id' => null,
            'game_player_id' => null,
            'type' => GameActionType::PhaseCheckpoint,
            'payload' => [
                'phase' => GamePhase::Actions->value,
                'game' => [
                    'status' => GameStatus::Active->value,
                    'round' => 1,
                    'phase' => GamePhase::Actions->value,
                    'active_player_id' => null,
                    'active_game_player_id' => $secondBot->id,
                    'version' => 0,
                    'state' => $state->toArray(),
                    'started_at' => null,
                    'finished_at' => null,
                ],
                'players' => [
                    ['id' => $firstBot->id],
                    ['id' => $secondBot->id],
                ],
                'final_scoring' => [],
            ],
            'events' => [],
            'state_version_before' => 0,
            'state_version_after' => 0,
        ]);
        $game->actions()->create([
            'sequence' => 2,
            'player_id' => null,
            'game_player_id' => $secondBot->id,
            'type' => GameActionType::ExchangeResources,
            'payload' => ['exchanges' => $this->resourceExchanges(powerToCoin: 1)],
            'events' => [],
            'state_version_before' => 0,
            'state_version_after' => 1,
        ]);

        app(ReplayGameHistoryAction::class)->execute(
            $game,
            $game->actions()->orderBy('sequence')->get(),
        );

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(6, $game->state->players[1]->resources->coins);
        $this->assertSame(1, $game->state->players[1]->resources->power->bowlOne);
        $this->assertSame(0, $game->state->players[1]->resources->power->bowlThree);
        $this->assertSame($secondBot->id, $game->active_game_player_id);
        $this->assertNull($game->active_player_id);

        $game->actions()->create([
            'sequence' => 3,
            'game_player_id' => $secondBot->id,
            'type' => GameActionType::BuildWorkshop,
            'payload' => ['hex_id' => 'target', 'tools' => 1, 'coins' => 2],
            'state_version_before' => 1,
            'state_version_after' => 2,
        ]);
        $finish = $game->actions()->create([
            'sequence' => 4,
            'game_player_id' => $secondBot->id,
            'type' => GameActionType::FinishTurn,
            'payload' => ['next_player_id' => $firstBot->id],
            'state_version_before' => 2,
            'state_version_after' => 3,
        ]);
        app(ReplayGameHistoryAction::class)->execute($game, $game->actions()->orderBy('sequence')->get());
        $this->assertSame($firstBot->id, $game->refresh()->active_game_player_id);

        app(UndoLastGameAction::class)->execute($game);

        $this->assertModelMissing($finish);
        $this->assertSame($secondBot->id, $game->refresh()->active_game_player_id);
        $this->assertNull($game->active_player_id);
        $this->assertSame($secondBot->id, $game->state->board->hexes[0]->building?->ownerPlayerId);
        $this->assertSame(4, $game->state->players[1]->resources->coins);
    }

    #[DataProvider('palaceRewardOrderProvider')]
    public function test_palace_fifteen_mixed_bridge_choices_are_replayed(bool $bridgesFirst): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
            'version' => 0,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $state = new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(
                hexes: [
                    new BoardHexStateData(
                        id: '8:5',
                        q: 8,
                        r: 5,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        adjacentHexIds: ['9:5'],
                        building: new BuildingStateData(BuildingType::Palace, $player->id),
                    ),
                    new BoardHexStateData(
                        id: '9:5',
                        q: 9,
                        r: 5,
                        initialTerrain: TerrainType::Wasteland,
                        terrain: TerrainType::Wasteland,
                        adjacentHexIds: ['8:5'],
                    ),
                    new BoardHexStateData(
                        id: '8:6',
                        q: 8,
                        r: 6,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                    new BoardHexStateData(
                        id: '7:6',
                        q: 7,
                        r: 6,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                    new BoardHexStateData(
                        id: '7:5',
                        q: 7,
                        r: 5,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                    new BoardHexStateData(
                        id: '7:7',
                        q: 7,
                        r: 7,
                        initialTerrain: TerrainType::Plains,
                        terrain: TerrainType::Plains,
                    ),
                    new BoardHexStateData(
                        id: '6:6',
                        q: 6,
                        r: 6,
                        initialTerrain: TerrainType::Mountain,
                        terrain: TerrainType::Mountain,
                    ),
                ],
                riverBankHexIds: ['8:5', '7:7', '6:6'],
            ),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
            )],
            availablePalaceIds: [PalaceAbility::Palace15->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChoosePalace,
                $player->id,
                [PalaceAbility::Palace15->value],
                ['reason' => 'building', 'builtHexId' => '8:5'],
            ),
        );
        $game->update(['state' => $state]);
        $game->actions()->create([
            'sequence' => 1,
            'player_id' => null,
            'game_player_id' => null,
            'type' => GameActionType::PhaseCheckpoint,
            'payload' => [
                'phase' => GamePhase::Actions->value,
                'game' => [
                    'status' => GameStatus::Active->value,
                    'round' => 1,
                    'phase' => GamePhase::Actions->value,
                    'active_player_id' => $user->id,
                    'version' => 0,
                    'state' => $state->toArray(),
                    'started_at' => null,
                    'finished_at' => null,
                ],
                'players' => [['id' => $player->id]],
                'final_scoring' => [],
            ],
            'events' => [],
            'state_version_before' => 0,
            'state_version_after' => 0,
        ]);

        $this->actingAs($user)->post(route('games.palace-choice', $game), [
            'palace_id' => PalaceAbility::Palace15->value,
        ])->assertNoContent();
        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 1, 'law' => 1, 'engineering' => 0, 'medicine' => 0],
        ])->assertNoContent();

        $this->post(route('games.palace-reward-order', $game), ['first_reward' => $bridgesFirst ? 'bridges' : 'spades'])->assertNoContent();
        if ($bridgesFirst) {
            $this->post(route('games.bridge.store', $game), ['from_hex_id' => '8:5', 'to_hex_id' => '7:7'])->assertNoContent();
            $this->post(route('games.bridge.confirm', $game))->assertNoContent();
            $this->post(route('games.bridge.skip', $game))->assertNoContent();
        }
        $this->post(route('games.paid-terraforming', $game), [
            'hex_id' => '9:5',
            'use_available' => false,
        ])->assertNoContent();
        $this->post(route('games.starting-spade.finish', $game))->assertNoContent();

        $game->refresh();
        $this->assertSame(PendingInteractionType::BuildWorkshopAfterTerraforming, $game->state->pendingInteraction?->type);
        $this->post(route('games.terraform-workshop', $game), [
            'build' => false,
            'hex_id' => '9:5',
        ])->assertNoContent();
        if (! $bridgesFirst) {
            $this->post(route('games.bridge.store', $game), [
                'from_hex_id' => '8:5',
                'to_hex_id' => '7:7',
            ])->assertNoContent();
            $this->post(route('games.bridge.confirm', $game))->assertNoContent();
            $this->post(route('games.bridge.skip', $game))->assertNoContent();
        }

        $game->refresh();
        $expectedState = $game->state;
        $this->assertSame(TerrainType::Forest, $expectedState->board->hexes[1]->terrain);
        $this->assertCount(1, $expectedState->board->bridges);
        $this->assertNull($expectedState->pendingInteraction);
        $this->assertSame([], $expectedState->pendingInteractionQueue);

        app(ReplayGameHistoryAction::class)->execute(
            $game,
            $game->actions()->orderBy('sequence')->get(),
        );

        $game->refresh();
        $this->assertSame($expectedState->board->hexes[1]->terrain, $game->state->board->hexes[1]->terrain);
        $bridgeValues = static fn (array $bridges): array => array_map(
            static fn (BridgeStateData $bridge): array => [
                $bridge->fromHexId,
                $bridge->toHexId,
                $bridge->ownerPlayerId,
            ],
            $bridges,
        );
        $this->assertSame(
            $bridgeValues($expectedState->board->bridges),
            $bridgeValues($game->state->board->bridges),
        );
        $this->assertSame($expectedState->players[0]->resources->books->toArray(), $game->state->players[0]->resources->books->toArray());
        $this->assertSame($expectedState->players[0]->unassignedSpades, $game->state->players[0]->unassignedSpades);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame([], $game->state->pendingInteractionQueue);
    }

    /** @return array<string, array{bool}> */
    public static function palaceRewardOrderProvider(): array
    {
        return ['spades first' => [false], 'bridges first' => [true]];
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
