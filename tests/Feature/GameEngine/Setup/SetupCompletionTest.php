<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Setup;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Board\Factories\BoardStateFactory;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Setup\Data\PlanningBundleData;
use App\Domain\GameEngine\Setup\Data\PlayerPlanningSelectionData;
use App\Domain\GameEngine\Setup\Factories\GamePlayerStateFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GameAction;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class SetupCompletionTest extends TestCase
{
    use RefreshDatabase;

    public function test_game_applies_income_and_enters_actions_when_no_income_choices_are_required(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Setup,
            'active_player_id' => $users[0]->id,
        ]);
        $firstPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
            'color' => PlayerColor::Green,
            'faction' => Faction::Blessed,
            'homeland' => TerrainType::Forest,
        ]);
        $secondPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
            'color' => PlayerColor::Grey,
            'faction' => Faction::Felines,
            'homeland' => TerrainType::Mountain,
        ]);
        $board = (new BoardStateFactory())->create(MapVariant::OneToThreePlayers);
        $forestHexIds = collect($board->hexes)
            ->where('terrain', TerrainType::Forest)
            ->take(2)
            ->pluck('id')
            ->all();
        $mountainHexIds = collect($board->hexes)
            ->where('terrain', TerrainType::Mountain)
            ->take(2)
            ->pluck('id')
            ->all();

        $this->assertCount(2, $forestHexIds);
        $this->assertCount(2, $mountainHexIds);

        $firstBundle = new PlanningBundleData(
            TerrainType::Forest,
            Faction::Blessed,
            RoundBonus::Coins,
        );
        $secondBundle = new PlanningBundleData(
            TerrainType::Mountain,
            Faction::Felines,
            RoundBonus::PowerCoins,
        );
        $playerStateFactory = app(GamePlayerStateFactory::class);

        $game->update([
            'state' => new GameStateData(
                schemaVersion: 3,
                turnOrder: [$firstPlayer->id, $secondPlayer->id],
                board: $board,
                players: [
                    $playerStateFactory->create($firstPlayer, $firstBundle),
                    $playerStateFactory->create($secondPlayer, $secondBundle),
                ],
                planningSelections: [
                    new PlayerPlanningSelectionData(
                        $firstPlayer->id,
                        $firstBundle,
                    ),
                    new PlayerPlanningSelectionData(
                        $secondPlayer->id,
                        $secondBundle,
                    ),
                ],
            ),
        ]);
        $resourcesBeforeIncome = collect($game->state->players)->mapWithKeys(
            static fn (GamePlayerStateData $playerState): array => [
                $playerState->playerId => [
                    'tools' => $playerState->resources->tools,
                    'coins' => $playerState->resources->coins,
                    'scholars' => $playerState->resources->scholars,
                ],
            ],
        );

        $placements = [
            [$users[0], $forestHexIds[0]],
            [$users[1], $mountainHexIds[0]],
            [$users[1], $mountainHexIds[1]],
            [$users[0], $forestHexIds[1]],
        ];

        foreach ($placements as [$user, $hexId]) {
            $this->actingAs($user)
                ->post(route('games.starting-building.store', $game), ['hex_id' => $hexId])
                ->assertNoContent();
            $this->post(route('games.starting-building.finish', $game))
                ->assertNoContent();
        }

        $game->refresh();

        $this->assertSame(4, $game->state->startingBuildingTurnIndex);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame(GamePhase::Actions, $game->state->round->phase);
        $this->assertSame($users[0]->id, $game->active_player_id);
        $this->assertNull($game->state->pendingStartingBuildingHexId);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertCount(6, $game->actions);
        $this->assertTrue($game->actions->whereNotIn('type', [GameActionType::PhaseCheckpoint, GameActionType::IncomePhase])->every(
            static fn (GameAction $action): bool => $action->type === GameActionType::PlaceStartingBuilding,
        ));
        $incomeStartingAction = $game->actions()
            ->where('type', GameActionType::PlaceStartingBuilding)
            ->latest('sequence')
            ->firstOrFail();
        $this->assertTrue($incomeStartingAction->payload['income_started']);
        $this->assertSame(1, $incomeStartingAction->payload['round']);
        $this->assertSame('income_phase_started', $incomeStartingAction->events[1]['type']);
        $this->assertSame(1, $incomeStartingAction->events[1]['round']);
        $incomePhaseAction = $game->actions()->where('type', GameActionType::IncomePhase)->sole();
        $this->assertCount(2, $incomePhaseAction->payload['income_receipts']);
        $this->assertEqualsCanonicalizing(
            [$firstPlayer->id, $secondPlayer->id],
            array_column($incomePhaseAction->payload['income_receipts'], 'player_id'),
        );

        foreach ($game->state->players as $playerState) {
            $income = PlayerIncomeCalculator::calculate($playerState, $game->state->board);
            $resourcesBefore = $resourcesBeforeIncome->get($playerState->playerId);

            $this->assertIsArray($resourcesBefore);
            $this->assertSame($resourcesBefore['tools'] + $income->tools, $playerState->resources->tools);
            $this->assertSame($resourcesBefore['coins'] + $income->coins, $playerState->resources->coins);
            $this->assertSame($resourcesBefore['scholars'] + $income->scholars, $playerState->resources->scholars);
        }

        $activePlayerState = collect($game->state->players)->firstWhere('userId', $users[0]->id);
        $this->assertInstanceOf(GamePlayerStateData::class, $activePlayerState);
        $this->assertGreaterThanOrEqual(4, $activePlayerState->resources->power->bowlTwo);
        $bowlTwoAtTurnStart = $activePlayerState->resources->power->bowlTwo;
        $bowlThreeAtTurnStart = $activePlayerState->resources->power->bowlThree;

        $this->actingAs($users[0]);
        $this->post(route('games.power-sacrifice.store', $game), ['amount' => 1]);
        $this->post(route('games.power-sacrifice.store', $game), ['amount' => 1]);

        $game->refresh();
        $this->assertCount(8, $game->actions);
        $this->assertSame(4, $game->state->round->turnStartVersion);
        $this->assertSame($bowlTwoAtTurnStart - 4, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame($bowlThreeAtTurnStart + 2, $game->state->players[0]->resources->power->bowlThree);
        $this->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.canRestartCurrentTurn', true)
                    ->where('game.data.canFinishCurrentTurn', false),
            );
        $this->post(route('games.current-turn.finish', $game))->assertForbidden();

        $this->actingAs($users[1])
            ->post(route('games.current-turn.restart', $game))
            ->assertForbidden();

        $this->actingAs($users[0])
            ->post(route('games.current-turn.restart', $game))
            ->assertNoContent();

        $game->refresh();
        $restartedPlayerState = collect($game->state->players)->firstWhere('userId', $users[0]->id);
        $this->assertInstanceOf(GamePlayerStateData::class, $restartedPlayerState);
        $this->assertSame($bowlTwoAtTurnStart, $restartedPlayerState->resources->power->bowlTwo);
        $this->assertSame($bowlThreeAtTurnStart, $restartedPlayerState->resources->power->bowlThree);
        $this->assertSame($users[0]->id, $game->active_player_id);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertNull($game->state->round->turnStartVersion);
        $this->assertSame(4, $game->version);
        $this->assertCount(4, $game->actions);
    }
}
