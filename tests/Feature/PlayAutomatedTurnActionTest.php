<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Actions\CreateAutomatedGameAction;
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
use App\Domain\Game\Data\PlanningBundleData;
use App\Domain\Game\Data\PlanningBundleOptionData;
use App\Domain\Game\Data\PlayerPlanningSelectionData;
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
use App\Domain\Game\Data\StartingBuildingOptionData;
use App\Domain\Game\Data\UpgradeBuildingOptionData;
use App\Domain\Game\Data\WorkshopAfterTerraformingOptionData;
use App\Domain\Game\Enums\AutomatedGamePassReason;
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
use App\Domain\Game\Services\AutomatedGameSimulator;
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
use App\Events\GameChanged;
use App\Jobs\PlayAutomatedTurnJob;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PlayAutomatedTurnActionTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_creates_reproducible_automated_games_from_a_seed(): void
    {
        Queue::fake();

        $first = app(CreateAutomatedGameAction::class)->execute('reproducible-bots', 2);
        $second = app(CreateAutomatedGameAction::class)->execute('reproducible-bots', 2);

        $this->assertSame($first->state->setupPool?->toArray(), $second->state->setupPool?->toArray());
        $this->assertSame($first->state->board->toArray(), $second->state->board->toArray());
        $this->assertCount(2, $first->players);
        $this->assertTrue($first->players->every(
            static fn (GamePlayer $player): bool => $player->bot_difficulty === GameBotDifficulty::Fast,
        ));
    }

    public function test_benchmark_bots_command_writes_game_and_summary_reports(): void
    {
        Queue::fake();
        Storage::fake('local');

        $exitCode = Artisan::call('game:benchmark-bots', [
            '--games' => 1,
            '--players' => 2,
            '--difficulty' => GameBotDifficulty::Fast->value,
            '--seed' => 'command-benchmark',
            '--max-decisions' => 1,
            '--max-duration' => 10_000,
        ]);

        $this->assertSame(1, $exitCode);
        Storage::disk('local')->assertExists('bot-reports/batches/command-benchmark/game-1.json');
        Storage::disk('local')->assertExists('bot-reports/batches/command-benchmark/summary.json');
        $summary = json_decode(
            Storage::disk('local')->get('bot-reports/batches/command-benchmark/summary.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        $this->assertSame(1, $summary['games']);
        $this->assertSame(1, $summary['decisions']);
        $this->assertSame(0, $summary['completed_games']);
    }

    public function test_automated_game_simulator_completes_a_final_round_and_collects_diagnostics(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'round' => 6,
        ]);
        $firstBot = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $secondBot = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 2,
        ]);
        $game->update([
            'active_game_player_id' => $firstBot->id,
            'state' => new GameStateData(
                turnOrder: [$firstBot->id, $secondBot->id],
                players: [$this->playerState($firstBot), $this->playerState($secondBot)],
                round: new RoundStateData(number: 6, phase: GamePhase::Actions),
                setupPool: app(GameSetupPoolFactory::class)->createFromSeed(2, 'bot-simulation-smoke'),
            ),
        ]);

        $result = app(AutomatedGameSimulator::class)->execute(
            $game,
            maxDecisions: 10,
            maxDurationMilliseconds: 10_000,
        );

        $this->assertTrue($result->completed);
        $this->assertNull($result->stoppedReason);
        $this->assertCount(2, $result->decisions);
        $this->assertSame(
            [GameActionType::Pass, GameActionType::Pass],
            array_column($result->decisions, 'actionType'),
        );
        $this->assertSame(
            [AutomatedGamePassReason::OnlyLegalAction, AutomatedGamePassReason::OnlyLegalAction],
            array_column($result->decisions, 'passReason'),
        );
        $this->assertNotEmpty($result->decisions[0]->candidates);
        $this->assertArrayHasKey($firstBot->id, $result->finalScores);
        $this->assertArrayHasKey($secondBot->id, $result->finalScores);
        $this->assertSame(GameStatus::Finished, $game->refresh()->status);
    }

    public function test_automated_game_simulator_rejects_invalid_limits(): void
    {
        $this->expectException(DomainException::class);

        app(AutomatedGameSimulator::class)->execute(
            Game::factory()->create(),
            maxDecisions: 0,
        );
    }

    public function test_simulate_bots_command_writes_a_detailed_report(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'round' => 6,
        ]);
        $firstBot = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
        ]);
        $secondBot = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 2,
        ]);
        $game->update([
            'active_game_player_id' => $firstBot->id,
            'state' => new GameStateData(
                turnOrder: [$firstBot->id, $secondBot->id],
                players: [$this->playerState($firstBot), $this->playerState($secondBot)],
                round: new RoundStateData(number: 6, phase: GamePhase::Actions),
                setupPool: app(GameSetupPoolFactory::class)->createFromSeed(2, 'bot-command-smoke'),
            ),
        ]);
        $reportPath = "bot-reports/game-{$game->id}.json";
        Storage::fake('local');

        $exitCode = Artisan::call('game:simulate-bots', [
            'game' => $game->id,
            '--max-decisions' => 10,
            '--max-duration' => 10_000,
        ]);

        $this->assertSame(0, $exitCode);

        Storage::disk('local')->assertExists($reportPath);
        $report = json_decode(Storage::disk('local')->get($reportPath), true, flags: JSON_THROW_ON_ERROR);
        $this->assertTrue($report['completed']);
        $this->assertCount(2, $report['decisions']);
        $this->assertSame('pass', $report['decisions'][0]['action_type']);
        $this->assertSame('only_legal_action', $report['decisions'][0]['selection_reason']);
        $this->assertSame('only_legal_action', $report['decisions'][0]['pass_reason']);
        $this->assertSame(1, $report['decisions'][0]['candidates'][0]['rank']);
        $this->assertTrue($report['decisions'][0]['candidates'][0]['selected']);
        $this->assertSame(['knowledgeDisciplines' => []], $report['decisions'][0]['candidates'][0]['parameters']);
        $this->assertGreaterThan(0, $report['decisions'][0]['search_timings']['simulation_calls']);
        $this->assertGreaterThanOrEqual(0, $report['decisions'][0]['search_timings']['simulation_ms']);
        $simulationTimings = $report['decisions'][0]['search_timings']['simulations_by_action'];
        $this->assertNotEmpty($simulationTimings);
        $this->assertSame($report['decisions'][0]['search_timings']['simulation_calls'], array_sum(array_column($simulationTimings, 'calls')));
        foreach ($simulationTimings as $timings) {
            $this->assertGreaterThan(0, $timings['average_ms']);
            $this->assertGreaterThanOrEqual($timings['average_ms'], $timings['maximum_ms']);
            $this->assertEqualsWithDelta($timings['total_ms'], $timings['state_copy_ms'] + $timings['execution_ms'], 0.000001);
        }
        foreach ($report['decisions'] as $decision) {
            foreach ($decision['candidates'] as $candidate) {
                $this->assertSame($candidate['score'], array_sum($candidate['score_breakdown']));
                $this->assertLessThanOrEqual(0, $candidate['score_breakdown']['pass_penalty']);
                $this->assertLessThanOrEqual(0, $candidate['score_breakdown']['opponent']);
            }
        }
        $this->assertNotEmpty($report['decisions'][0]['action_availability']);
        $this->assertSame(
            'build_workshop',
            $report['decisions'][0]['action_availability'][0]['type'],
        );
        $this->assertContains(
            'insufficient_tools',
            $report['decisions'][0]['action_availability'][0]['unavailable_reasons'],
        );
        $this->assertSame([
            'coins' => 0,
            'tools' => 0,
            'scholars' => 0,
            'books' => 0,
            'power' => 0,
            'spades' => 0,
        ], $report['decisions'][0]['remaining_resources']);
    }

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
                && $job->gamePlayerId === $game->players()->whereBelongsTo($bot)->value('id')
                && $job->queue === PlayAutomatedTurnJob::QUEUE,
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

    public function test_bot_places_and_confirms_a_starting_building_before_handing_control_to_human(): void
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
            'homeland' => TerrainType::Forest,
        ]);
        $human = User::factory()->create();
        $humanPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $human->id,
            'seat' => 2,
            'faction' => Faction::Felines,
            'homeland' => TerrainType::Mountain,
        ]);
        $botState = $this->playerState($botPlayer);
        $humanState = new GamePlayerStateData(
            playerId: $humanPlayer->id,
            userId: $human->id,
            color: PlayerColor::Red,
            faction: Faction::Felines,
            homeland: TerrainType::Mountain,
            roundBonus: RoundBonus::PowerCoins,
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id, $humanPlayer->id],
                board: new BoardStateData(hexes: [
                    new BoardHexStateData(
                        id: '0:0',
                        q: 0,
                        r: 0,
                        initialTerrain: TerrainType::Forest,
                        terrain: TerrainType::Forest,
                    ),
                    new BoardHexStateData(
                        id: '1:0',
                        q: 1,
                        r: 0,
                        initialTerrain: TerrainType::Mountain,
                        terrain: TerrainType::Mountain,
                    ),
                ]),
                players: [$botState, $humanState],
                round: new RoundStateData(phase: GamePhase::Setup),
                planningSelections: [
                    new PlayerPlanningSelectionData(
                        $botPlayer->id,
                        new PlanningBundleData(TerrainType::Forest, Faction::Blessed, RoundBonus::Coins),
                    ),
                    new PlayerPlanningSelectionData(
                        $humanPlayer->id,
                        new PlanningBundleData(TerrainType::Mountain, Faction::Felines, RoundBonus::PowerCoins),
                    ),
                ],
            ),
        ]);

        app(PlayAutomatedTurnAction::class)->execute($game, $botPlayer, GameBotDifficulty::Balanced);

        $game->refresh();
        $this->assertSame($humanPlayer->id, $game->active_game_player_id);
        $this->assertSame(1, $game->state->startingBuildingTurnIndex);
        $this->assertNull($game->state->pendingStartingBuildingHexId);
        $this->assertSame(
            $botPlayer->id,
            collect($game->state->board->hexes)->firstWhere('id', '0:0')?->building?->ownerPlayerId,
        );
        $this->assertSame(
            [GameActionType::PlaceStartingBuilding],
            $game->actions()->pluck('type')->all(),
        );
    }

    public function test_starting_building_simulation_matches_execution_for_bot_without_user(): void
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
            'homeland' => TerrainType::Forest,
        ]);
        $humanPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
            'faction' => Faction::Felines,
            'homeland' => TerrainType::Mountain,
        ]);
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id, $humanPlayer->id],
                board: new BoardStateData(hexes: [
                    new BoardHexStateData('forest', 0, 0, TerrainType::Forest, TerrainType::Forest),
                ]),
                players: [
                    $this->playerState($botPlayer),
                    new GamePlayerStateData(
                        $humanPlayer->id,
                        $humanPlayer->user_id,
                        PlayerColor::Red,
                        Faction::Felines,
                        TerrainType::Mountain,
                        RoundBonus::PowerCoins,
                    ),
                ],
                round: new RoundStateData(phase: GamePhase::Setup),
                planningSelections: [
                    new PlayerPlanningSelectionData(
                        $botPlayer->id,
                        new PlanningBundleData(TerrainType::Forest, Faction::Blessed, RoundBonus::Coins),
                    ),
                    new PlayerPlanningSelectionData(
                        $humanPlayer->id,
                        new PlanningBundleData(TerrainType::Mountain, Faction::Felines, RoundBonus::PowerCoins),
                    ),
                ],
            ),
        ]);

        $this->assertSimulationMatchesExecution(
            $game,
            $botPlayer,
            new StartingBuildingOptionData('forest'),
        );
    }

    public function test_starting_competency_simulation_matches_execution_for_bot_without_user(): void
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
            'faction' => Faction::Monks,
            'homeland' => TerrainType::Mountain,
        ]);
        $humanPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
            'faction' => Faction::Blessed,
            'homeland' => TerrainType::Forest,
        ]);
        $botState = new GamePlayerStateData(
            $botPlayer->id,
            null,
            PlayerColor::Yellow,
            Faction::Monks,
            TerrainType::Mountain,
            RoundBonus::Coins,
        );
        $humanState = $this->playerState($humanPlayer);
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id, $humanPlayer->id],
                board: new BoardStateData(hexes: [
                    new BoardHexStateData('mountain', 0, 0, TerrainType::Mountain, TerrainType::Mountain),
                    new BoardHexStateData('wasteland', 1, 0, TerrainType::Wasteland, TerrainType::Wasteland),
                ]),
                players: [$botState, $humanState],
                round: new RoundStateData(phase: GamePhase::Setup),
                availableCompetencyIds: [Competency::Competency05->value],
                planningSelections: [
                    new PlayerPlanningSelectionData(
                        $botPlayer->id,
                        new PlanningBundleData(TerrainType::Mountain, Faction::Monks, RoundBonus::Coins),
                    ),
                    new PlayerPlanningSelectionData(
                        $humanPlayer->id,
                        new PlanningBundleData(TerrainType::Forest, Faction::Blessed, RoundBonus::PowerCoins),
                    ),
                ],
                startingBuildingTurnIndex: 3,
                pendingInteraction: new PendingInteractionData(
                    PendingInteractionType::ChooseCompetency,
                    $botPlayer->id,
                    [Competency::Competency05->value],
                ),
            ),
        ]);

        $this->assertSimulationMatchesExecution(
            $game,
            $botPlayer,
            new ChooseCompetencyOptionData(Competency::Competency05),
        );
    }

    public function test_starting_neutral_tower_competency_simulation_matches_execution(): void
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
            'faction' => Faction::Monks,
            'homeland' => TerrainType::Mountain,
        ]);
        $humanPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
            'faction' => Faction::Blessed,
            'homeland' => TerrainType::Forest,
        ]);
        $botState = new GamePlayerStateData(
            $botPlayer->id,
            null,
            PlayerColor::Yellow,
            Faction::Monks,
            TerrainType::Mountain,
            RoundBonus::Coins,
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                turnOrder: [$botPlayer->id, $humanPlayer->id],
                board: new BoardStateData(hexes: [
                    new BoardHexStateData(
                        id: 'mountain-built',
                        q: 0,
                        r: 0,
                        initialTerrain: TerrainType::Mountain,
                        terrain: TerrainType::Mountain,
                        adjacentHexIds: ['mountain-free'],
                        building: new BuildingStateData(BuildingType::University, $botPlayer->id),
                    ),
                    new BoardHexStateData(
                        id: 'mountain-free',
                        q: 1,
                        r: 0,
                        initialTerrain: TerrainType::Mountain,
                        terrain: TerrainType::Mountain,
                        adjacentHexIds: ['mountain-built'],
                    ),
                ]),
                players: [$botState, $this->playerState($humanPlayer)],
                round: new RoundStateData(phase: GamePhase::Setup),
                availableCompetencyIds: [Competency::Competency10->value],
                planningSelections: [
                    new PlayerPlanningSelectionData(
                        $botPlayer->id,
                        new PlanningBundleData(TerrainType::Mountain, Faction::Monks, RoundBonus::Coins),
                    ),
                    new PlayerPlanningSelectionData(
                        $humanPlayer->id,
                        new PlanningBundleData(TerrainType::Forest, Faction::Blessed, RoundBonus::PowerCoins),
                    ),
                ],
                startingBuildingTurnIndex: 3,
                pendingInteraction: new PendingInteractionData(
                    PendingInteractionType::ChooseCompetency,
                    $botPlayer->id,
                    [Competency::Competency10->value],
                ),
            ),
        ]);

        $this->assertSimulationMatchesExecution(
            $game,
            $botPlayer,
            new ChooseCompetencyOptionData(Competency::Competency10),
        );
    }

    public function test_final_starting_competency_simulation_matches_income_transition(): void
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
            'faction' => Faction::Monks,
            'homeland' => TerrainType::Mountain,
        ]);
        $humanPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'seat' => 2,
            'faction' => Faction::Blessed,
            'homeland' => TerrainType::Forest,
        ]);
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'final-starting-competency');
        $setupPool->competencies = Competency::cases();
        $botState = new GamePlayerStateData(
            $botPlayer->id,
            null,
            PlayerColor::Yellow,
            Faction::Monks,
            TerrainType::Mountain,
            RoundBonus::Coins,
        );
        $game->update([
            'active_game_player_id' => $botPlayer->id,
            'state' => new GameStateData(
                schemaVersion: 3,
                turnOrder: [$botPlayer->id, $humanPlayer->id],
                players: [$botState, $this->playerState($humanPlayer)],
                round: new RoundStateData(phase: GamePhase::Setup),
                availableCompetencyIds: [Competency::Competency04->value],
                setupPool: $setupPool,
                planningSelections: [
                    new PlayerPlanningSelectionData(
                        $botPlayer->id,
                        new PlanningBundleData(TerrainType::Mountain, Faction::Monks, RoundBonus::Coins),
                    ),
                    new PlayerPlanningSelectionData(
                        $humanPlayer->id,
                        new PlanningBundleData(TerrainType::Forest, Faction::Blessed, RoundBonus::PowerCoins),
                    ),
                ],
                startingBuildingTurnIndex: 3,
                pendingInteraction: new PendingInteractionData(
                    PendingInteractionType::ChooseCompetency,
                    $botPlayer->id,
                    [Competency::Competency04->value],
                ),
            ),
        ]);

        $this->assertSimulationMatchesExecution(
            $game,
            $botPlayer,
            new ChooseCompetencyOptionData(Competency::Competency04),
        );
    }

    public function test_bots_complete_starting_building_setup_without_users(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
        ]);
        $firstBot = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 1,
            'faction' => Faction::Blessed,
            'homeland' => TerrainType::Forest,
        ]);
        $secondBot = GamePlayer::factory()->bot(GameBotDifficulty::Fast)->create([
            'game_id' => $game->id,
            'user_id' => null,
            'seat' => 2,
            'faction' => Faction::Felines,
            'homeland' => TerrainType::Mountain,
        ]);
        $firstState = $this->playerState($firstBot);
        $secondState = new GamePlayerStateData(
            $secondBot->id,
            null,
            PlayerColor::Red,
            Faction::Felines,
            TerrainType::Mountain,
            RoundBonus::PowerCoins,
        );
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'automated-starting-buildings');
        $game->update([
            'active_game_player_id' => $firstBot->id,
            'state' => new GameStateData(
                turnOrder: [$firstBot->id, $secondBot->id],
                board: new BoardStateData(hexes: [
                    new BoardHexStateData('forest-1', 0, 0, TerrainType::Forest, TerrainType::Forest),
                    new BoardHexStateData('forest-2', 1, 0, TerrainType::Forest, TerrainType::Forest),
                    new BoardHexStateData('mountain-1', 2, 0, TerrainType::Mountain, TerrainType::Mountain),
                    new BoardHexStateData('mountain-2', 3, 0, TerrainType::Mountain, TerrainType::Mountain),
                ]),
                players: [$firstState, $secondState],
                round: new RoundStateData(phase: GamePhase::Setup),
                setupPool: $setupPool,
                planningSelections: [
                    new PlayerPlanningSelectionData(
                        $firstBot->id,
                        new PlanningBundleData(TerrainType::Forest, Faction::Blessed, RoundBonus::Coins),
                    ),
                    new PlayerPlanningSelectionData(
                        $secondBot->id,
                        new PlanningBundleData(TerrainType::Mountain, Faction::Felines, RoundBonus::PowerCoins),
                    ),
                ],
            ),
        ]);

        for ($turn = 0; $turn < 4 && $game->refresh()->phase === GamePhase::Setup; $turn++) {
            $activeBot = $game->active_game_player_id === $firstBot->id ? $firstBot : $secondBot;
            app(PlayAutomatedTurnAction::class)->execute($game, $activeBot, GameBotDifficulty::Fast);
        }

        $game->refresh();
        $this->assertNotSame(GamePhase::Setup, $game->phase);
        $this->assertSame(4, $game->state->startingBuildingTurnIndex);
        $this->assertCount(
            4,
            collect($game->state->board->hexes)->filter(
                static fn (BoardHexStateData $hex): bool => $hex->building !== null,
            ),
        );
        $this->assertCount(4, $game->actions()->where('type', GameActionType::PlaceStartingBuilding)->get());
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
        Queue::fake();

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

        Event::fake([GameChanged::class]);

        (new PlayAutomatedTurnJob($game->id, $botPlayer->id))
            ->handle(app(PlayAutomatedTurnAction::class));

        $game->refresh();
        $this->assertSame($botPlayer->id, $game->active_game_player_id);
        $this->assertTrue($game->state->round->hasTakenMainAction);
        $this->assertSame(
            [GameActionType::BookAction],
            $game->actions()->orderBy('sequence')->pluck('type')->all(),
        );
        Queue::assertPushed(
            PlayAutomatedTurnJob::class,
            fn (PlayAutomatedTurnJob $job): bool => $job->gameId === $game->id
                && $job->gamePlayerId === $botPlayer->id,
        );

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
        Event::assertDispatched(
            GameChanged::class,
            fn (GameChanged $event): bool => $event->gameId === $game->id,
        );
        Event::assertDispatchedTimes(GameChanged::class, 2);
    }

    public function test_a_new_job_does_not_repeat_an_auxiliary_action_from_the_current_turn(): void
    {
        Queue::fake();

        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'version' => 1,
        ]);
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
                players: [$this->playerState(
                    $botPlayer,
                    books: new BookSupplyData(banking: 1, law: 1),
                )],
                round: new RoundStateData(
                    phase: GamePhase::Actions,
                    turnStartVersion: 0,
                ),
                setupPool: $setupPool,
            ),
        ]);
        GameAction::factory()->create([
            'game_id' => $game->id,
            'sequence' => 1,
            'player_id' => null,
            'game_player_id' => $botPlayer->id,
            'type' => GameActionType::ExchangeResources,
            'state_version_before' => 0,
            'state_version_after' => 1,
        ]);

        app(PlayAutomatedTurnAction::class)->execute(
            $game,
            $botPlayer,
            GameBotDifficulty::Fast,
            singleDecision: true,
        );

        $this->assertSame(
            [GameActionType::ExchangeResources, GameActionType::BookAction],
            $game->actions()->orderBy('sequence')->pluck('type')->all(),
        );
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
