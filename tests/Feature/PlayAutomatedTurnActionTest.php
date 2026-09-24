<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Actions\PerformGameActionOptionAction;
use App\Domain\Game\Actions\PlayAutomatedTurnAction;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\BuildWorkshopOptionData;
use App\Domain\Game\Data\ChoosePalaceOptionData;
use App\Domain\Game\Data\ChooseTownOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerActionOptionData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Data\UpgradeBuildingOptionData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\PowerAction;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TownTile;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Domain\Game\Services\PaidTerraformingOptionFinder;
use App\Jobs\PlayAutomatedTurnJob;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class PlayAutomatedTurnActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_changing_active_player_dispatches_automated_turn_for_bot(): void
    {
        Queue::fake();

        $bot = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
        ]);
        GamePlayer::factory()->bot(GameBotDifficulty::Strong)->create([
            'game_id' => $game->id,
            'user_id' => $bot->id,
            'seat' => 1,
        ]);

        $game->update(['active_player_id' => $bot->id]);

        Queue::assertPushed(
            PlayAutomatedTurnJob::class,
            fn (PlayAutomatedTurnJob $job): bool => $job->gameId === $game->id
                && $job->gamePlayerId === $game->players()->whereBelongsTo($bot)->value('id'),
        );
    }

    public function test_changing_active_player_does_not_dispatch_automated_turn_for_human(): void
    {
        Queue::fake();

        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
        ]);
        GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);

        $game->update(['active_player_id' => $user->id]);

        Queue::assertNothingPushed();
    }

    public function test_it_chooses_performs_and_confirms_a_complete_turn(): void
    {
        $opponent = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $opponentPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $opponent->id,
            'seat' => 2,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->create(2);
        $setupPool->bookActions = [BookAction::GainCoins];
        $game->update(['state' => new GameStateData(
            turnOrder: [$botPlayer->id, $opponentPlayer->id],
            players: [
                $this->playerState($botPlayer, books: new BookSupplyData(banking: 1, law: 1)),
                $this->playerState($opponentPlayer),
            ],
            round: new RoundStateData(phase: GamePhase::Actions),
            setupPool: $setupPool,
        ), 'active_game_player_id' => $botPlayer->id]);

        (new PlayAutomatedTurnJob($game->id, $botPlayer->id))
            ->handle(app(PlayAutomatedTurnAction::class));

        $game->refresh();
        $this->assertSame($opponent->id, $game->active_player_id);
        $this->assertSame(6, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->state->players[0]->resources->books->banking);
        $this->assertSame(0, $game->state->players[0]->resources->books->law);
        $this->assertFalse($game->state->round->hasTakenMainAction);
        $this->assertNull($game->state->round->turnStartVersion);
        $this->assertSame(
            [GameActionType::BookAction, GameActionType::FinishTurn],
            $game->actions()->orderBy('sequence')->pluck('type')->all(),
        );
        $this->assertSame([$botPlayer->id, $botPlayer->id], $game->actions()->pluck('game_player_id')->all());
        $this->assertSame([null, null], $game->actions()->pluck('player_id')->all());
    }

    public function test_it_resolves_its_pending_decision_and_stops_when_control_changes(): void
    {
        $opponent = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $opponentPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $opponent->id,
            'seat' => 2,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->resources->power = new PowerBowlsStateData(bowlOne: 1);
        $game->update(['state' => new GameStateData(
            turnOrder: [$opponentPlayer->id, $botPlayer->id],
            players: [$botState, $this->playerState($opponentPlayer)],
            round: new RoundStateData(phase: GamePhase::Actions, hasTakenMainAction: true),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::PowerOffer,
                $botPlayer->id,
                context: [
                    'buildingPlayerId' => $opponentPlayer->id,
                    'builtHexId' => '0:0',
                    'powerAmount' => 1,
                    'remainingOffers' => [],
                ],
            ),
        ), 'active_game_player_id' => $botPlayer->id]);

        app(PlayAutomatedTurnAction::class)->execute($game, $botPlayer, GameBotDifficulty::Fast);

        $game->refresh();
        $this->assertSame($opponent->id, $game->active_player_id);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(1, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame([GameActionType::AcceptPower], $game->actions()->pluck('type')->all());
        $this->assertSame([$botPlayer->id], $game->actions()->pluck('game_player_id')->all());
        $this->assertSame([null], $game->actions()->pluck('player_id')->all());
    }

    public function test_bot_without_user_can_choose_a_palace(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
        ]);
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id],
                players: [$this->playerState($botPlayer)],
                round: new RoundStateData(phase: GamePhase::Actions),
                availablePalaceIds: [PalaceAbility::Palace17->value],
                pendingInteraction: new PendingInteractionData(
                    PendingInteractionType::ChoosePalace,
                    $botPlayer->id,
                    [PalaceAbility::Palace17->value],
                    ['builtHexId' => '0:0'],
                ),
            ),
        ]);

        app(PerformGameActionOptionAction::class)->execute(
            $game,
            $botPlayer,
            new ChoosePalaceOptionData(PalaceAbility::Palace17),
        );

        $game->refresh();
        $this->assertSame(PalaceAbility::Palace17->value, $game->state->players[0]->palaceId);
        $this->assertSame([$botPlayer->id], $game->actions()->pluck('game_player_id')->all());
        $this->assertSame([null], $game->actions()->pluck('player_id')->all());
    }

    public function test_bot_without_user_can_perform_a_power_action(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->resources->power = new PowerBowlsStateData(bowlThree: 12);
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id],
                players: [$botState],
                round: new RoundStateData(phase: GamePhase::Actions),
            ),
        ]);

        app(PerformGameActionOptionAction::class)->execute(
            $game,
            $botPlayer,
            new PowerActionOptionData(PowerAction::GainCoins, 0),
        );

        $game->refresh();
        $this->assertSame(7, $game->state->players[0]->resources->coins);
        $this->assertContains(PowerAction::GainCoins->value, $game->state->round->usedSharedActionIds);
        $this->assertSame([$botPlayer->id], $game->actions()->pluck('game_player_id')->all());
        $this->assertSame([null], $game->actions()->pluck('player_id')->all());
    }

    public function test_bot_without_user_can_build_a_workshop(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->resources->tools = 1;
        $botState->resources->coins = 2;
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id],
                board: new BoardStateData(hexes: [
                    new BoardHexStateData(
                        id: '0:0',
                        q: 0,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        adjacentHexIds: ['1:0'],
                        building: new BuildingStateData(BuildingType::Workshop, $botPlayer->id),
                    ),
                    new BoardHexStateData(
                        id: '1:0',
                        q: 1,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        adjacentHexIds: ['0:0'],
                    ),
                ]),
                players: [$botState],
                round: new RoundStateData(phase: GamePhase::Actions),
            ),
        ]);

        app(PerformGameActionOptionAction::class)->execute(
            $game,
            $botPlayer,
            new BuildWorkshopOptionData('1:0'),
        );

        $game->refresh();
        $this->assertSame(BuildingType::Workshop, $game->state->board->hexes[1]->building?->type);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
        $this->assertSame([$botPlayer->id], $game->actions()->pluck('game_player_id')->all());
        $this->assertSame([null], $game->actions()->pluck('player_id')->all());
    }

    public function test_bot_without_user_can_choose_a_town_tile(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
        ]);
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id],
                players: [$this->playerState($botPlayer)],
                round: new RoundStateData(phase: GamePhase::Actions),
                availableTownTileIds: [TownTile::Tools->value],
                pendingInteraction: new PendingInteractionData(
                    PendingInteractionType::ChooseTown,
                    $botPlayer->id,
                    [TownTile::Tools->value],
                    ['freePalaceTownTile' => true],
                ),
            ),
        ]);

        app(PerformGameActionOptionAction::class)->execute(
            $game,
            $botPlayer,
            new ChooseTownOptionData(TownTile::Tools),
        );

        $game->refresh();
        $this->assertSame(3, $game->state->players[0]->resources->tools);
        $this->assertSame([TownTile::Tools->value], $game->state->players[0]->townTileIds);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame([$botPlayer->id], $game->actions()->pluck('game_player_id')->all());
        $this->assertSame([null], $game->actions()->pluck('player_id')->all());
    }

    public function test_bot_without_user_can_upgrade_a_building(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->resources->tools = 10;
        $botState->resources->coins = 10;
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id],
                board: new BoardStateData(hexes: [
                    new BoardHexStateData(
                        id: '0:0',
                        q: 0,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        building: new BuildingStateData(BuildingType::Workshop, $botPlayer->id),
                    ),
                ]),
                players: [$botState],
                round: new RoundStateData(phase: GamePhase::Actions),
            ),
        ]);

        app(PerformGameActionOptionAction::class)->execute(
            $game,
            $botPlayer,
            new UpgradeBuildingOptionData(
                '0:0',
                BuildingType::Workshop,
                BuildingType::Guild,
                tools: 0,
                coins: 0,
            ),
        );

        $game->refresh();
        $this->assertSame(BuildingType::Guild, $game->state->board->hexes[0]->building?->type);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame([$botPlayer->id], $game->actions()->pluck('game_player_id')->all());
        $this->assertSame([null], $game->actions()->pluck('player_id')->all());
    }

    public function test_bot_without_user_can_perform_paid_terraforming(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->resources->tools = 10;
        $state = new GameStateData(
            turnOrder: [$botPlayer->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData(BuildingType::Workshop, $botPlayer->id),
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
            players: [$botState],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => $state,
        ]);
        $option = app(PaidTerraformingOptionFinder::class)->execute($state, $botState)[0];

        app(PerformGameActionOptionAction::class)->execute($game, $botPlayer, $option);

        $game->refresh();
        $this->assertSame(TerrainType::Forest, $game->state->board->hexes[1]->terrain);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame([$botPlayer->id], $game->actions()->pluck('game_player_id')->all());
        $this->assertSame([null], $game->actions()->pluck('player_id')->all());
    }

    private function playerState(GamePlayer $player, ?BookSupplyData $books = null): GamePlayerStateData
    {
        return new GamePlayerStateData(
            playerId: $player->id,
            userId: $player->user_id,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(books: $books ?? new BookSupplyData()),
        );
    }
}
