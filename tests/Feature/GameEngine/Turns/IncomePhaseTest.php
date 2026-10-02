<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Turns;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Actions\ResolveIncomePhaseAction;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IncomePhaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_income_skips_players_without_choices_and_stops_on_a_required_choice(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create();
        $firstPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
        ]);
        $secondPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
        ]);
        $firstPlayerState = new GamePlayerStateData(
            $firstPlayer->id,
            $users[0]->id,
            PlayerColor::Green,
            Faction::Blessed,
            TerrainType::Forest,
            RoundBonus::Coins,
        );
        $secondPlayerState = new GamePlayerStateData(
            $secondPlayer->id,
            $users[1]->id,
            PlayerColor::Grey,
            Faction::Felines,
            TerrainType::Mountain,
            RoundBonus::Bridge,
        );
        $state = new GameStateData(
            turnOrder: [$firstPlayer->id, $secondPlayer->id],
            board: new BoardStateData(),
            players: [$firstPlayerState, $secondPlayerState],
        );
        [$activePlayer, $phase] = app(ResolveIncomePhaseAction::class)->execute($state);

        $this->assertSame($secondPlayer->id, $activePlayer->playerId);
        $this->assertSame(GamePhase::Income, $phase);
        $this->assertSame(GamePhase::Income, $state->round->phase);
        $this->assertSame(1, $state->round->incomeTurnIndex);
        $this->assertSame([$secondPlayer->id], $state->round->incomeOrder);
        $this->assertSame(PendingInteractionType::ChooseStartingResources, $state->pendingInteraction?->type);
        $this->assertSame($secondPlayer->id, $state->pendingInteraction?->playerId);
        $this->assertSame(1, $state->pendingInteraction?->context['bookCount']);
        $this->assertSame([], $state->round->incomeReceipts);
        $this->assertSame(0, $firstPlayerState->resources->tools);
        $this->assertSame(0, $firstPlayerState->resources->coins);
        $this->assertSame(1, $secondPlayerState->resources->books->unassigned);

        $secondPlayerState->resources->books->unassigned = 0;
        $secondPlayerTools = $secondPlayerState->resources->tools;
        [$activePlayer, $phase] = app(ResolveIncomePhaseAction::class)->execute($state);

        $this->assertSame($firstPlayer->id, $activePlayer->playerId);
        $this->assertSame(GamePhase::Actions, $phase);
        $this->assertSame(GamePhase::Actions, $state->round->phase);
        $this->assertSame(1, $firstPlayerState->resources->tools);
        $this->assertSame(6, $firstPlayerState->resources->coins);
        $this->assertSame($secondPlayerTools + 1, $secondPlayerState->resources->tools);
        $this->assertSame([], $state->round->incomeOrder);
        $this->assertSame([], $state->round->incomeReceipts);
    }

    public function test_income_continues_automatically_after_required_resources_are_distributed(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Income,
            'active_player_id' => $users[0]->id,
        ]);
        $firstPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[0]->id,
            'seat' => 1,
        ]);
        $secondPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $users[1]->id,
            'seat' => 2,
        ]);
        $firstPlayerState = new GamePlayerStateData(
            $firstPlayer->id,
            $users[0]->id,
            PlayerColor::Green,
            Faction::Blessed,
            TerrainType::Forest,
            RoundBonus::Coins,
        );
        $firstPlayerState->resources->books->unassigned = 1;
        $secondPlayerState = new GamePlayerStateData(
            $secondPlayer->id,
            $users[1]->id,
            PlayerColor::Grey,
            Faction::Felines,
            TerrainType::Mountain,
            RoundBonus::Coins,
        );
        $state = new GameStateData(
            turnOrder: [$firstPlayer->id, $secondPlayer->id],
            board: new BoardStateData(),
            players: [$firstPlayerState, $secondPlayerState],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseStartingResources,
                $firstPlayer->id,
                array_column(KnowledgeDiscipline::cases(), 'value'),
                [
                    'bookCount' => 1,
                    'knowledgeStepCount' => 0,
                    'competencyIds' => [],
                    'phase' => GamePhase::Income->value,
                ],
            ),
        );
        $state->round->phase = GamePhase::Income;
        $state->round->incomeTurnIndex = 1;
        $state->round->incomeOrder = [$firstPlayer->id];
        $state->round->incomeReceipts = [];
        $game->update(['state' => $state]);

        $this->actingAs($users[0])->post(route('games.rewards', $game), [
            'book_counts' => [
                'banking' => 1,
                'law' => 0,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertNoContent();

        $game->refresh();
        $updatedFirstPlayer = collect($game->state->players)->firstWhere('playerId', $firstPlayer->id);
        $updatedSecondPlayer = collect($game->state->players)->firstWhere('playerId', $secondPlayer->id);

        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame(GamePhase::Actions, $game->state->round->phase);
        $this->assertSame($users[0]->id, $game->active_player_id);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(0, $updatedFirstPlayer?->resources->books->unassigned);
        $this->assertSame(1, $updatedFirstPlayer?->resources->books->banking);
        $this->assertSame(1, $updatedSecondPlayer?->resources->tools);
        $this->assertSame(8, $updatedSecondPlayer?->resources->coins);
        $this->assertSame(
            GameActionType::ChooseIncomeResources,
            $game->actions()->where('type', GameActionType::ChooseIncomeResources)->latest('sequence')->firstOrFail()->type,
        );
        $this->assertEquals([
            [
                'player_id' => $firstPlayer->id,
                'tools' => 1,
                'coins' => 6,
                'scholars' => 0,
                'power' => 0,
                'books' => 0,
                'knowledge_steps' => 0,
                'victory_points' => 0,
            ],
            [
                'player_id' => $secondPlayer->id,
                'tools' => 1,
                'coins' => 8,
                'scholars' => 0,
                'power' => 0,
                'books' => 0,
                'knowledge_steps' => 0,
                'victory_points' => 0,
            ],
        ], $game->actions()->where('type', GameActionType::IncomePhase)->latest('sequence')->firstOrFail()->payload['income_receipts']);
    }

    public function test_manual_knowledge_step_reaching_level_nine_is_applied_before_other_income(): void
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Income,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
        ]);
        $playerState = new GamePlayerStateData(
            playerId: $player->id,
            userId: $user->id,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            knowledge: new KnowledgeStateData(banking: 8),
            competencyIds: [Competency::Competency01->value],
        );
        $state = new GameStateData(
            turnOrder: [$player->id],
            players: [$playerState],
        );
        $state->round->phase = GamePhase::Income;

        [$activePlayer, $phase] = app(ResolveIncomePhaseAction::class)->execute($state);
        $game->update([
            'active_player_id' => $activePlayer->userId,
            'phase' => $phase,
            'state' => $state,
        ]);

        $this->assertSame(0, $playerState->resources->coins);
        $this->assertSame(1, $playerState->knowledge->unassignedSteps);

        $this->actingAs($user)->post(route('games.rewards', $game), [
            'knowledge_counts' => [
                'banking' => 1,
                'law' => 0,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame(9, $game->state->players[0]->knowledge->banking);
        $this->assertSame(9, $game->state->players[0]->resources->coins);
        $this->assertSame(2, $game->state->players[0]->resources->tools);
    }
}
