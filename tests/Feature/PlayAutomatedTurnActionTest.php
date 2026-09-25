<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Actions\PerformGameActionOptionAction;
use App\Domain\Game\Actions\PlayAutomatedTurnAction;
use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BookActionOptionData;
use App\Domain\Game\Data\BookPaymentData;
use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\BuildWorkshopOptionData;
use App\Domain\Game\Data\ChooseCompetencyOptionData;
use App\Domain\Game\Data\ChoosePalaceOptionData;
use App\Domain\Game\Data\ChooseRoundBonusOptionData;
use App\Domain\Game\Data\ChooseTownOptionData;
use App\Domain\Game\Data\DevelopmentAdvancementOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PalaceWaterTownOptionData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlaceBridgeOptionData;
use App\Domain\Game\Data\PlaceNeutralBuildingOptionData;
use App\Domain\Game\Data\PlacePalaceGuildOptionData;
use App\Domain\Game\Data\PlanningBundleOptionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PlayerSpecialActionOptionData;
use App\Domain\Game\Data\PowerActionOptionData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\PowerOfferOptionData;
use App\Domain\Game\Data\ResourceExchangeOptionData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Data\SacrificePowerOptionData;
use App\Domain\Game\Data\SendScholarOptionData;
use App\Domain\Game\Data\SpendSpadesOptionData;
use App\Domain\Game\Data\UpgradeBuildingOptionData;
use App\Domain\Game\Data\WorkshopAfterTerraformingOptionData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Competency;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\Innovation;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\PowerAction;
use App\Domain\Game\Enums\ResourceExchange;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\RoundScoringTile;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TownTile;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Domain\Game\Services\DevelopmentAdvancementOptionFinder;
use App\Domain\Game\Services\GameActionOptionFinder;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\InnovationSpecialActionOptionFinder;
use App\Domain\Game\Services\MakeInnovationOptionFinder;
use App\Domain\Game\Services\PaidTerraformingOptionFinder;
use App\Domain\Game\Services\PalaceActionOptionFinder;
use App\Domain\Game\Services\PassOptionFinder;
use App\Domain\Game\Services\PlaceAnnexOptionFinder;
use App\Domain\Game\Services\PlayerSpecialActionOptionFinder;
use App\Domain\Game\Services\ResourceConversionOptionFinder;
use App\Jobs\PlayAutomatedTurnJob;
use App\Models\Game;
use App\Models\GameAction;
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

    public function test_changing_active_player_dispatches_automated_turn_for_bot_during_planning(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Balanced)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'bot-planning-dispatch');
        $game->update(['state' => new GameStateData(
            turnOrder: [$botPlayer->id],
            round: new RoundStateData(phase: GamePhase::Setup),
            setupPool: $setupPool,
        )]);

        $game->update(['active_game_player_id' => $botPlayer->id]);

        Queue::assertPushed(
            PlayAutomatedTurnJob::class,
            fn (PlayAutomatedTurnJob $job): bool => $job->gameId === $game->id
                && $job->gamePlayerId === $botPlayer->id,
        );
    }

    public function test_bot_without_user_selects_a_planning_bundle_and_hands_control_to_human(): void
    {
        Queue::fake();

        $human = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $humanPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $human->id,
            'seat' => 2,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'bot-planning-selection');
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id, $humanPlayer->id],
                round: new RoundStateData(phase: GamePhase::Setup),
                setupPool: $setupPool,
            ),
        ]);

        app(PlayAutomatedTurnAction::class)->execute($game, $botPlayer, GameBotDifficulty::Fast);

        $game->refresh();
        $botPlayer->refresh();
        $this->assertNotNull($botPlayer->faction);
        $this->assertNotNull($botPlayer->homeland);
        $this->assertSame($humanPlayer->id, $game->active_game_player_id);
        $this->assertSame($human->id, $game->active_player_id);
        $this->assertCount(1, $game->state->planningSelections);
        $this->assertSame($botPlayer->id, $game->state->players[0]->playerId);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(
            [GameActionType::ChoosePlanningBundle],
            $game->actions()->pluck('type')->all(),
        );
    }

    public function test_planning_bundle_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $nextPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'planning-simulation');
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id, $nextPlayer->id],
                round: new RoundStateData(phase: GamePhase::Setup),
                setupPool: $setupPool,
            ),
        ]);

        $this->assertSimulationMatchesExecution(
            $game,
            $botPlayer,
            new PlanningBundleOptionData($setupPool->planningBundles[0]->homeland),
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

        $this->assertSimulationMatchesExecution(
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

        $this->assertSimulationMatchesExecution(
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

        $this->assertSimulationMatchesExecution(
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

        $this->assertSimulationMatchesExecution(
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

        $this->assertSimulationMatchesExecution(
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

        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);

        $game->refresh();
        $this->assertSame(TerrainType::Forest, $game->state->board->hexes[1]->terrain);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame([$botPlayer->id], $game->actions()->pluck('game_player_id')->all());
        $this->assertSame([null], $game->actions()->pluck('player_id')->all());
    }

    public function test_bot_without_user_can_advance_development_tracks(): void
    {
        Queue::fake();

        foreach ([GameActionType::AdvanceShipping, GameActionType::AdvanceTerraforming] as $actionType) {
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
            $botState->resources->scholars = 10;
            $state = new GameStateData(
                turnOrder: [$botPlayer->id],
                players: [$botState],
                round: new RoundStateData(phase: GamePhase::Actions),
            );
            $game->update([
                'active_game_player_id' => $botPlayer->id,
                'state' => $state,
            ]);
            $option = collect(app(DevelopmentAdvancementOptionFinder::class)->execute($state, $botState))
                ->first(fn ($candidate): bool => $candidate->action === $actionType);
            $this->assertInstanceOf(DevelopmentAdvancementOptionData::class, $option);

            $this->assertSimulationMatchesExecution($game, $botPlayer, $option);

            $game->refresh();
            $this->assertSame(1, $actionType === GameActionType::AdvanceShipping
                ? $game->state->players[0]->shippingLevel
                : $game->state->players[0]->terraformingLevel);
            $this->assertSame([$actionType], $game->actions()->pluck('type')->all());
            $this->assertSame([$botPlayer->id], $game->actions()->pluck('game_player_id')->all());
            $this->assertSame([null], $game->actions()->pluck('player_id')->all());
        }
    }

    public function test_bot_without_user_can_send_a_scholar(): void
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
        $botState->resources->scholars = 1;
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id],
                players: [$botState],
                round: new RoundStateData(phase: GamePhase::Actions),
            ),
        ]);

        $this->assertSimulationMatchesExecution(
            $game,
            $botPlayer,
            new SendScholarOptionData(KnowledgeDiscipline::Banking, false, 1, null),
        );

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(1, $game->state->players[0]->knowledge->banking);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame([$botPlayer->id], $game->actions()->pluck('game_player_id')->all());
        $this->assertSame([null], $game->actions()->pluck('player_id')->all());
    }

    public function test_resource_conversion_simulations_match_execution_for_bot_without_user(): void
    {
        Queue::fake();

        foreach ([ResourceExchangeOptionData::class, SacrificePowerOptionData::class] as $optionClass) {
            $game = Game::factory()->create([
                'status' => GameStatus::Active,
                'phase' => GamePhase::Actions,
            ]);
            $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
                'game_id' => $game->id,
                'user_id' => null,
            ]);
            $botState = $this->playerState($botPlayer);
            $botState->resources->power = new PowerBowlsStateData(bowlTwo: 4, bowlThree: 5);
            $state = new GameStateData(
                turnOrder: [$botPlayer->id],
                players: [$botState],
                round: new RoundStateData(phase: GamePhase::Actions),
            );
            $game->update([
                'active_game_player_id' => $botPlayer->id,
                'state' => $state,
            ]);
            $options = collect(app(ResourceConversionOptionFinder::class)->execute($botState));
            $option = $optionClass === ResourceExchangeOptionData::class
                ? $options->first(
                    static fn (GameActionOption $candidate): bool => $candidate instanceof ResourceExchangeOptionData
                        && $candidate->exchange === ResourceExchange::PowerToBook
                        && $candidate->discipline === KnowledgeDiscipline::Law,
                )
                : $options->first(
                    static fn (GameActionOption $candidate): bool => $candidate instanceof SacrificePowerOptionData,
                );

            $this->assertInstanceOf($optionClass, $option);
            $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
        }
    }

    public function test_innovation_simulations_match_execution_for_bot_without_user(): void
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
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'innovation-execution-parity');
        $setupPool->innovations[0] = Innovation::LeagueOfCities;
        $botState = $this->playerState(
            $botPlayer,
            new BookSupplyData(banking: 2, law: 2, medicine: 1),
        );
        $botState->resources->coins = 10;
        $botState->townTileIds = [TownTile::Tools->value, TownTile::Coins->value];
        $state = new GameStateData(
            turnOrder: [$botPlayer->id],
            players: [$botState],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableInventionIds: [Innovation::LeagueOfCities->value],
            setupPool: $setupPool,
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => $state,
        ]);
        $option = app(MakeInnovationOptionFinder::class)->execute($state, $botState)[0];

        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
    }

    public function test_special_action_simulations_match_execution_for_bot_without_user(): void
    {
        Queue::fake();

        foreach ([Innovation::Professor, null] as $innovation) {
            $game = Game::factory()->create([
                'status' => GameStatus::Active,
                'phase' => GamePhase::Actions,
            ]);
            $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
                'game_id' => $game->id,
                'user_id' => null,
            ]);
            $botState = $this->playerState($botPlayer);
            $botState->faction = $innovation instanceof Innovation ? Faction::Blessed : Faction::Philosophers;
            $botState->inventionIds = $innovation instanceof Innovation ? [$innovation->value] : [];
            $state = new GameStateData(
                turnOrder: [$botPlayer->id],
                players: [$botState],
                round: new RoundStateData(phase: GamePhase::Actions),
            );
            $game->update([
                'active_game_player_id' => $botPlayer->id,
                'state' => $state,
            ]);
            $options = $innovation instanceof Innovation
                ? app(InnovationSpecialActionOptionFinder::class)->execute($state, $botState)
                : app(PlayerSpecialActionOptionFinder::class)->execute($state, $botState);
            $option = $options[0];

            $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
        }
    }

    public function test_pass_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $opponent = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'pass-execution-parity');
        $setupPool->availableRoundBonuses = [
            new RoundBonusOfferData(RoundBonus::RiverWorkshop, 2),
            new RoundBonusOfferData(RoundBonus::BuildGuild, 1),
        ];
        $botState = $this->playerState($botPlayer);
        $state = new GameStateData(
            turnOrder: [$botPlayer->id, $opponent->id],
            players: [$botState, $this->playerState($opponent)],
            round: new RoundStateData(phase: GamePhase::Actions),
            setupPool: $setupPool,
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => $state,
        ]);
        $option = app(PassOptionFinder::class)->execute($state, $botState)[0];

        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
    }

    public function test_annex_simulation_matches_execution_for_bot_without_user(): void
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
        $botState->availableAnnexes = 1;
        $state = new GameStateData(
            turnOrder: [$botPlayer->id],
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Workshop, $botPlayer->id),
            )]),
            players: [$botState],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => $state,
        ]);
        $option = app(PlaceAnnexOptionFinder::class)->execute($state, $botState)[0];

        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
    }

    public function test_bridge_simulation_matches_staged_and_confirmed_execution_for_bot_without_user(): void
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
        $state = new GameStateData(
            schemaVersion: 4,
            turnOrder: [$botPlayer->id],
            board: new BoardStateData(
                hexes: [
                    new BoardHexStateData(
                        id: '0:0',
                        q: 0,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                        building: new BuildingStateData(BuildingType::Workshop, $botPlayer->id),
                    ),
                    new BoardHexStateData(
                        id: '1:1',
                        q: 1,
                        r: 1,
                        initialTerrain: TerrainType::Mountain,
                        terrain: TerrainType::Mountain,
                    ),
                    new BoardHexStateData(
                        id: '1:0',
                        q: 1,
                        r: 0,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                    new BoardHexStateData(
                        id: '0:1',
                        q: 0,
                        r: 1,
                        initialTerrain: TerrainType::Water,
                        terrain: TerrainType::Water,
                    ),
                ],
                riverBankHexIds: ['0:0', '1:1'],
            ),
            players: [$this->playerState($botPlayer)],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::PlaceBridge,
                $botPlayer->id,
                context: ['source' => 'power'],
            ),
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => $state,
        ]);
        $option = collect(app(GameActionOptionFinder::class)->execute($state, $botPlayer->id))
            ->first(static fn (GameActionOption $candidate): bool => $candidate instanceof PlaceBridgeOptionData);

        $this->assertInstanceOf(PlaceBridgeOptionData::class, $option);
        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
    }

    public function test_palace_guild_simulation_matches_staged_and_confirmed_execution_for_bot_without_user(): void
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
        $state = new GameStateData(
            schemaVersion: 4,
            turnOrder: [$botPlayer->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Palace, $botPlayer->id),
                ),
                new BoardHexStateData(
                    id: '5:5',
                    q: 5,
                    r: 5,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                ),
            ]),
            players: [$this->playerState($botPlayer)],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::PlacePalaceGuild,
                $botPlayer->id,
                ['5:5'],
                ['palaceBuiltHexId' => '0:0', 'selectedHexId' => null],
            ),
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => $state,
        ]);
        $option = collect(app(GameActionOptionFinder::class)->execute($state, $botPlayer->id))
            ->first(static fn (GameActionOption $candidate): bool => $candidate instanceof PlacePalaceGuildOptionData);

        $this->assertInstanceOf(PlacePalaceGuildOptionData::class, $option);
        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
    }

    public function test_neutral_building_simulation_matches_execution_for_bot_without_user(): void
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
        $botState->resources->tools = 3;
        $state = new GameStateData(
            schemaVersion: 4,
            turnOrder: [$botPlayer->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData(BuildingType::School, $botPlayer->id),
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
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::PlaceNeutralBuilding,
                $botPlayer->id,
                ['1:0'],
                [
                    'buildingType' => BuildingType::Tower->value,
                    'source' => 'competency',
                    'queuedBuiltHexIds' => ['0:0'],
                ],
            ),
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => $state,
        ]);
        GameAction::factory()->create([
            'game_id' => $game->id,
            'game_player_id' => $botPlayer->id,
            'player_id' => null,
            'sequence' => 1,
            'type' => GameActionType::ChooseCompetency,
            'state_version_before' => 0,
            'state_version_after' => 0,
        ]);
        $option = collect(app(GameActionOptionFinder::class)->execute($state, $botPlayer->id))
            ->first(static fn (GameActionOption $candidate): bool => $candidate instanceof PlaceNeutralBuildingOptionData);

        $this->assertInstanceOf(PlaceNeutralBuildingOptionData::class, $option);
        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
    }

    public function test_competency_simulation_matches_execution_for_bot_without_user(): void
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
        $state = new GameStateData(
            schemaVersion: 4,
            turnOrder: [$botPlayer->id],
            players: [$this->playerState($botPlayer)],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableCompetencyIds: [Competency::Competency04->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseCompetency,
                $botPlayer->id,
                [Competency::Competency04->value],
                ['reason' => 'innovation'],
            ),
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => $state,
        ]);
        $option = collect(app(GameActionOptionFinder::class)->execute($state, $botPlayer->id))
            ->first(static fn (GameActionOption $candidate): bool => $candidate instanceof ChooseCompetencyOptionData);

        $this->assertInstanceOf(ChooseCompetencyOptionData::class, $option);
        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
    }

    public function test_workshop_after_terraforming_simulations_match_execution_for_bot_without_user(): void
    {
        Queue::fake();

        foreach ([true, false] as $build) {
            $game = Game::factory()->create([
                'status' => GameStatus::Active,
                'phase' => GamePhase::Actions,
            ]);
            $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
                'game_id' => $game->id,
                'user_id' => null,
            ]);
            $botState = $this->playerState($botPlayer);
            $botState->resources->coins = 4;
            $botState->resources->tools = 2;
            $state = new GameStateData(
                turnOrder: [$botPlayer->id],
                board: new BoardStateData(hexes: [new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                )]),
                players: [$botState],
                round: new RoundStateData(phase: GamePhase::Actions, hasTakenMainAction: true),
                pendingInteraction: new PendingInteractionData(
                    PendingInteractionType::BuildWorkshopAfterTerraforming,
                    $botPlayer->id,
                    ['0:0'],
                    ['toolCost' => 1, 'coinCost' => 2],
                ),
            );
            $game->update([
                'active_game_player_id' => $botPlayer->id,
                'state' => $state,
            ]);
            $option = collect(app(GameActionOptionFinder::class)->execute($state, $botPlayer->id))->first(
                static fn (GameActionOption $candidate): bool => $candidate instanceof WorkshopAfterTerraformingOptionData
                    && $candidate->build === $build,
            );

            $this->assertInstanceOf(WorkshopAfterTerraformingOptionData::class, $option);
            $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
        }
    }

    public function test_palace_water_town_simulations_match_execution_for_bot_without_user(): void
    {
        Queue::fake();

        foreach ([true, false] as $accept) {
            $game = Game::factory()->create([
                'status' => GameStatus::Active,
                'phase' => GamePhase::Actions,
            ]);
            $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
                'game_id' => $game->id,
                'user_id' => null,
            ]);
            $state = new GameStateData(
                turnOrder: [$botPlayer->id],
                players: [$this->playerState($botPlayer)],
                round: new RoundStateData(phase: GamePhase::Actions),
                availableTownTileIds: [TownTile::Coins->value],
                pendingInteraction: new PendingInteractionData(
                    PendingInteractionType::OfferPalaceWaterTown,
                    $botPlayer->id,
                    context: [
                        'townsByWaterHexId' => ['water' => ['land']],
                        'builtHexId' => 'land',
                        'queuedBuiltHexIds' => [],
                    ],
                ),
            );
            $game->update([
                'active_game_player_id' => $botPlayer->id,
                'state' => $state,
            ]);
            $option = collect(app(GameActionOptionFinder::class)->execute($state, $botPlayer->id))->first(
                static fn (GameActionOption $candidate): bool => $candidate instanceof PalaceWaterTownOptionData
                    && $candidate->accept === $accept,
            );

            $this->assertInstanceOf(PalaceWaterTownOptionData::class, $option);
            $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
        }
    }

    public function test_action_phase_reward_distribution_simulations_match_execution_for_bot_without_user(): void
    {
        Queue::fake();

        $scenarios = [
            [PendingInteractionType::ChooseTownBooks, 2, 0, null],
            [PendingInteractionType::ChooseFelineTownBonus, 1, 3, null],
            [PendingInteractionType::ChooseInnovationReward, 1, 3, GameActionType::MakeInnovation],
            [PendingInteractionType::ChooseShippingBooks, 2, 0, GameActionType::AdvanceShipping],
            [PendingInteractionType::ChooseTerraformingBooks, 2, 0, GameActionType::AdvanceTerraforming],
            [PendingInteractionType::ChoosePalaceBooks, 2, 0, GameActionType::ChoosePalace],
        ];

        foreach ($scenarios as [$interactionType, $bookCount, $knowledgeStepCount, $sourceActionType]) {
            $game = Game::factory()->create([
                'status' => GameStatus::Active,
                'phase' => GamePhase::Actions,
            ]);
            $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
                'game_id' => $game->id,
                'user_id' => null,
            ]);
            $botState = $this->playerState($botPlayer);
            if ($interactionType === PendingInteractionType::ChooseFelineTownBonus) {
                $botState->faction = Faction::Felines;
                $botState->color = PlayerColor::Red;
                $botState->homeland = TerrainType::Desert;
            }
            $botState->resources->books->unassigned = $bookCount;
            $botState->knowledge->unassignedSteps = $knowledgeStepCount;
            $state = new GameStateData(
                schemaVersion: 4,
                turnOrder: [$botPlayer->id],
                players: [$botState],
                round: new RoundStateData(phase: GamePhase::Actions),
                pendingInteraction: new PendingInteractionData(
                    $interactionType,
                    $botPlayer->id,
                    context: [
                        'bookCount' => $bookCount,
                        'knowledgeStepCount' => $knowledgeStepCount,
                        'builtHexId' => '0:0',
                    ],
                ),
            );
            $game->update([
                'active_game_player_id' => $botPlayer->id,
                'state' => $state,
            ]);

            if ($sourceActionType instanceof GameActionType) {
                GameAction::factory()->create([
                    'game_id' => $game->id,
                    'game_player_id' => $botPlayer->id,
                    'player_id' => null,
                    'sequence' => 1,
                    'type' => $sourceActionType,
                    'state_version_before' => 0,
                    'state_version_after' => 0,
                ]);
            }

            $option = collect(app(GameActionOptionFinder::class)->execute($state, $botPlayer->id))
                ->first(static fn (GameActionOption $candidate): bool => $candidate instanceof RewardDistributionOptionData);

            $this->assertInstanceOf(RewardDistributionOptionData::class, $option);
            $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
        }
    }

    public function test_science_bonus_reward_distribution_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::ScienceBonus,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $opponent = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
            'faction' => Faction::Inventors,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->resources->books->unassigned = 3;
        $opponentState = $this->playerState($opponent);
        $opponentState->faction = Faction::Inventors;
        $opponentState->color = PlayerColor::Red;
        $opponentState->homeland = TerrainType::Desert;
        $opponentState->knowledge->law = 3;
        $state = new GameStateData(
            schemaVersion: 4,
            turnOrder: [$botPlayer->id, $opponent->id],
            players: [$botState, $opponentState],
            round: new RoundStateData(
                phase: GamePhase::ScienceBonus,
                scoringTileId: RoundScoringTile::GuildLaw->value,
                scienceBonusTurnIndex: 1,
            ),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseScienceBonusBooks,
                $botPlayer->id,
                context: ['bookCount' => 3],
            ),
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => $state,
        ]);
        $option = collect(app(GameActionOptionFinder::class)->execute($state, $botPlayer->id))
            ->first(static fn (GameActionOption $candidate): bool => $candidate instanceof RewardDistributionOptionData);

        $this->assertInstanceOf(RewardDistributionOptionData::class, $option);
        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
    }

    public function test_income_reward_distribution_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Income,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $opponent = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->resources->books->unassigned = 1;
        $botState->knowledge->unassignedSteps = 2;
        $opponentState = $this->playerState($opponent);
        $opponentState->resources->books->unassigned = 1;
        $state = new GameStateData(
            schemaVersion: 4,
            turnOrder: [$botPlayer->id, $opponent->id],
            players: [$botState, $opponentState],
            round: new RoundStateData(
                phase: GamePhase::Income,
                incomeTurnIndex: 1,
                incomeOrder: [$botPlayer->id, $opponent->id],
            ),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseStartingResources,
                $botPlayer->id,
                context: ['bookCount' => 1, 'knowledgeStepCount' => 2],
            ),
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => $state,
        ]);
        $option = collect(app(GameActionOptionFinder::class)->execute($state, $botPlayer->id))
            ->first(static fn (GameActionOption $candidate): bool => $candidate instanceof RewardDistributionOptionData);

        $this->assertInstanceOf(RewardDistributionOptionData::class, $option);
        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
    }

    public function test_setup_reward_distribution_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
        ]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
            'faction' => Faction::Blessed,
        ]);
        $nextPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
            'faction' => null,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->resources->books->unassigned = 1;
        $state = new GameStateData(
            schemaVersion: 4,
            turnOrder: [$botPlayer->id, $nextPlayer->id],
            players: [$botState],
            round: new RoundStateData(phase: GamePhase::Setup),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseStartingResources,
                $botPlayer->id,
                context: ['bookCount' => 1, 'knowledgeStepCount' => 0],
            ),
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => $state,
        ]);
        $option = collect(app(GameActionOptionFinder::class)->execute($state, $botPlayer->id))
            ->first(static fn (GameActionOption $candidate): bool => $candidate instanceof RewardDistributionOptionData);

        $this->assertInstanceOf(RewardDistributionOptionData::class, $option);
        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
    }

    public function test_book_action_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();
        $game = Game::factory()->create(['status' => GameStatus::Active, 'phase' => GamePhase::Actions]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->create(2);
        $setupPool->bookActions = [BookAction::GainCoins];
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id],
                players: [$this->playerState($botPlayer, new BookSupplyData(banking: 1, law: 1))],
                round: new RoundStateData(phase: GamePhase::Actions),
                setupPool: $setupPool,
            ),
        ]);

        $this->assertSimulationMatchesExecution(
            $game,
            $botPlayer,
            new BookActionOptionData(BookAction::GainCoins, new BookPaymentData(banking: 1, law: 1)),
        );
    }

    public function test_palace_action_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();
        $game = Game::factory()->create(['status' => GameStatus::Active, 'phase' => GamePhase::Actions]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->palaceId = PalaceAbility::Palace13->value;
        $state = new GameStateData(
            turnOrder: [$botPlayer->id],
            players: [$botState],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
        $game->update(['active_game_player_id' => $botPlayer->id, 'state' => $state]);
        $option = collect(app(PalaceActionOptionFinder::class)->execute($state, $botState))
            ->firstWhere('discipline', KnowledgeDiscipline::Law);

        $this->assertInstanceOf(GameActionOption::class, $option);
        $this->assertSimulationMatchesExecution($game, $botPlayer, $option);
    }

    public function test_power_offer_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();
        $game = Game::factory()->create(['status' => GameStatus::Active, 'phase' => GamePhase::Actions]);
        $buildingPlayer = GamePlayer::factory()->create(['game_id' => $game->id, 'seat' => 1]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 2,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->resources->power = new PowerBowlsStateData(bowlOne: 2);
        $state = new GameStateData(
            turnOrder: [$buildingPlayer->id, $botPlayer->id],
            players: [$this->playerState($buildingPlayer), $botState],
            round: new RoundStateData(phase: GamePhase::Actions, hasTakenMainAction: true),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::PowerOffer,
                $botPlayer->id,
                context: [
                    'buildingPlayerId' => $buildingPlayer->id,
                    'builtHexId' => '0:0',
                    'powerAmount' => 2,
                    'remainingOffers' => [],
                ],
            ),
        );
        $game->update(['active_game_player_id' => $botPlayer->id, 'state' => $state]);

        $this->assertSimulationMatchesExecution($game, $botPlayer, new PowerOfferOptionData(true));
    }

    public function test_round_bonus_choice_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();
        $game = Game::factory()->create(['status' => GameStatus::Active, 'phase' => GamePhase::Actions]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $nextPlayer = GamePlayer::factory()->create(['game_id' => $game->id, 'seat' => 2]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'round-bonus-execution-parity');
        $setupPool->availableRoundBonuses = [
            new RoundBonusOfferData(RoundBonus::RiverWorkshop, 2),
            new RoundBonusOfferData(RoundBonus::BuildGuild, 1),
        ];
        $state = new GameStateData(
            turnOrder: [$botPlayer->id, $nextPlayer->id],
            passedPlayerIds: [$botPlayer->id],
            players: [$this->playerState($botPlayer), $this->playerState($nextPlayer)],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                passOrder: [$botPlayer->id],
            ),
            setupPool: $setupPool,
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseRoundBonus,
                $botPlayer->id,
                [RoundBonus::RiverWorkshop->value, RoundBonus::BuildGuild->value],
            ),
        );
        $game->update(['active_game_player_id' => $botPlayer->id, 'state' => $state]);

        $this->assertSimulationMatchesExecution(
            $game,
            $botPlayer,
            new ChooseRoundBonusOptionData(RoundBonus::RiverWorkshop, 2),
        );
    }

    public function test_competency_special_action_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();
        $game = Game::factory()->create(['status' => GameStatus::Active, 'phase' => GamePhase::Actions]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->competencyIds = [Competency::Competency07->value];
        $state = new GameStateData(
            turnOrder: [$botPlayer->id],
            players: [$botState],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
        $game->update(['active_game_player_id' => $botPlayer->id, 'state' => $state]);

        $this->assertSimulationMatchesExecution(
            $game,
            $botPlayer,
            new PlayerSpecialActionOptionData(GameActionOptionType::UseCompetencyAction),
        );
    }

    public function test_round_bonus_special_action_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();
        $game = Game::factory()->create(['status' => GameStatus::Active, 'phase' => GamePhase::Actions]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->roundBonus = RoundBonus::Knowledge;
        $state = new GameStateData(
            turnOrder: [$botPlayer->id],
            players: [$botState],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
        $game->update(['active_game_player_id' => $botPlayer->id, 'state' => $state]);

        $this->assertSimulationMatchesExecution(
            $game,
            $botPlayer,
            new PlayerSpecialActionOptionData(
                GameActionOptionType::UseRoundBonusAction,
                KnowledgeDiscipline::Law,
            ),
        );
    }

    public function test_spend_spades_simulation_matches_execution_for_bot_without_user(): void
    {
        Queue::fake();
        $game = Game::factory()->create(['status' => GameStatus::Active, 'phase' => GamePhase::Actions]);
        $botPlayer = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
        ]);
        $botState = $this->playerState($botPlayer);
        $botState->unassignedSpades = 1;
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
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::SpendSpades,
                $botPlayer->id,
                ['1:0'],
                [
                    'remainingSpades' => 1,
                    'targetTerrain' => TerrainType::Forest->value,
                ],
            ),
        );
        $game->update(['active_game_player_id' => $botPlayer->id, 'state' => $state]);

        $this->assertSimulationMatchesExecution(
            $game,
            $botPlayer,
            new SpendSpadesOptionData('1:0', 1),
        );
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

    private function assertSimulationMatchesExecution(
        Game $game,
        GamePlayer $player,
        GameActionOption $option,
    ): void {
        $simulation = app(GameActionSimulator::class)->execute($game->state, $player->id, $option);

        app(PerformGameActionOptionAction::class)->execute($game, $player, $option);

        $game->refresh();

        $this->assertEquals(
            $this->comparableState($simulation->state),
            $this->comparableState($game->state),
        );
        $this->assertSame($simulation->nextActivePlayerId, $game->active_game_player_id);
    }

    /** @return array<string, mixed> */
    private function comparableState(GameStateData $state): array
    {
        $comparableState = GameStateData::from($state->toArray());
        $comparableState->turnStartSnapshot = null;
        $comparableState->round->turnStartVersion = null;
        $comparableState->townChoiceCheckpoint = null;

        return json_decode(
            json_encode($comparableState->toArray(), JSON_THROW_ON_ERROR),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
    }
}
