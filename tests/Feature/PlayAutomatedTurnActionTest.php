<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Domain\Game\Actions\PerformGameActionOptionAction;
use App\Domain\Game\Actions\PlayAutomatedTurnAction;
use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\ChoosePalaceOptionData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionType;
use App\Domain\Game\Enums\GameBotDifficulty;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\PalaceAbility;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\GameSetupPoolFactory;
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
