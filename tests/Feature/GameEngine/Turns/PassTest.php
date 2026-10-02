<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Turns;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\PlayerAbilities\Data\RoundBonusOfferData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Data\KnowledgeStateData;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
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

class PassTest extends TestCase
{
    use RefreshDatabase;

    public function test_player_can_pass_only_on_their_turn_and_choose_an_available_round_bonus(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $state = $game->state;
        $state->round->hasTakenMainAction = true;
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ])->assertForbidden();

        $state->round->hasTakenMainAction = false;
        $game->update(['state' => $state]);

        $this->actingAs($secondUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ])->assertForbidden();

        $this->actingAs($firstUser)->post(route('games.pass', $game))->assertNoContent();

        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::Spade->value,
        ])->assertSessionHasErrors('round_bonus');

        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame($secondUser->id, $game->active_player_id);
        $this->assertSame(RoundBonus::RiverWorkshop, $game->state->players[0]->roundBonus);
        $this->assertSame([$game->state->players[0]->playerId], $game->state->passedPlayerIds);
        $this->assertSame([$game->state->players[0]->playerId], $game->state->round->passOrder);
        $this->assertSame(2, $game->state->players[0]->resources->coins);
        $this->assertContains(
            RoundBonus::Knowledge,
            array_column($game->state->setupPool?->availableRoundBonuses ?? [], 'roundBonus'),
        );
        $this->assertSame(
            [GameActionType::Pass, GameActionType::ChooseRoundBonus],
            $game->actions()->orderBy('sequence')->pluck('type')->all(),
        );

        $this->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page
                ->where('game.data.playerBoardStates.0.passOrder', 1)
                ->where('game.data.canPass', false),
        );
    }

    public function test_pass_school_round_bonus_advances_knowledge_once_per_school(): void
    {
        [$game, $firstUser] = $this->gameForPassing();
        $state = $game->state;
        $player = $state->players[0];
        $player->roundBonus = RoundBonus::PassSchool;
        $player->knowledge = new KnowledgeStateData(banking: 2, law: 4);
        $player->resources->power = new PowerBowlsStateData(bowlOne: 3);
        $state->round->scoringTileId = RoundScoringTile::KnowledgeMedicine->value;
        $state->board = new BoardStateData(hexes: [
            new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::School, $player->playerId),
            ),
            new BoardHexStateData(
                id: '1:0',
                q: 1,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::School, $player->playerId),
            ),
        ]);
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ])->assertSessionHasErrors('knowledge_counts');

        $this->post(route('games.pass', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
            'knowledge_counts' => [
                KnowledgeDiscipline::Banking->value => 1,
                KnowledgeDiscipline::Law->value => 1,
                KnowledgeDiscipline::Engineering->value => 0,
                KnowledgeDiscipline::Medicine->value => 0,
            ],
        ])->assertNoContent()->assertSessionHasNoErrors();

        $game->refresh();
        $this->assertSame(3, $game->state->players[0]->knowledge->banking);
        $this->assertSame(5, $game->state->players[0]->knowledge->law);
        $this->assertSame(22, $game->state->players[0]->victoryPoints);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(3, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(
            [KnowledgeDiscipline::Banking->value, KnowledgeDiscipline::Law->value],
            $game->actions()->sole()->payload['knowledge_disciplines'],
        );
        $this->assertSame(2, $game->actions()->sole()->payload['victory_points']);
        $this->assertSame([[
            'id' => RoundScoringTile::KnowledgeMedicine->value,
            'points' => 2,
            'source' => 'round_scoring',
        ]], $game->actions()->sole()->payload['scoring_sources']);
    }

    public function test_player_discards_their_round_bonus_without_choosing_a_new_one_in_the_final_round(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $state = $game->state;
        $state->round->number = 6;
        $state->players[0]->resources->coins = 4;
        $state->players[0]->resources->tools = 2;
        $state->players[0]->resources->scholars = 1;
        $state->players[0]->resources->books->banking = 1;
        $state->players[0]->resources->books->law = 1;
        $state->players[0]->resources->power->bowlTwo = 5;
        $state->players[0]->resources->power->bowlThree = 1;
        $state->players[1]->resources->coins = 2;
        $state->players[1]->resources->tools = 1;
        $state->players[1]->resources->power->bowlTwo = 2;
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)
            ->post(route('games.pass', $game))
            ->assertNoContent()
            ->assertSessionHasNoErrors();

        $game->refresh();
        $this->assertSame($secondUser->id, $game->active_player_id);
        $this->assertSame(RoundBonus::Knowledge, $game->state->players[0]->roundBonus);
        $this->assertSame(2, $game->state->players[0]->resources->coins);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(1, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame(3, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(22, $game->state->players[0]->victoryPoints);
        $this->assertCount(4, $game->state->setupPool?->availableRoundBonuses);
        $this->assertContains(
            RoundBonus::Knowledge,
            array_column($game->state->setupPool?->availableRoundBonuses ?? [], 'roundBonus'),
        );

        $action = $game->actions()->sole();
        $this->assertArrayNotHasKey('round_bonus', $action->payload);
        $this->assertArrayNotHasKey('bonus_coins', $action->payload);
        $this->assertEquals([
            'bowlTwoSpent' => 4,
            'movedToBowlThree' => 2,
            'convertedToCoins' => 8,
            'totalCoins' => 12,
            'victoryPoints' => 2,
            'remainingCoins' => 2,
        ], $action->payload['final_resource_conversion']);

        $this->actingAs($secondUser)
            ->post(route('games.pass', $game))
            ->assertNoContent()
            ->assertSessionHasNoErrors();

        $game->refresh();
        $this->assertSame(GameStatus::Finished, $game->status);
        $this->assertSame(GamePhase::Finished, $game->phase);
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(0, $game->actions()->where('type', GameActionType::ScienceBonusPhase)->count());
        $this->assertFalse(
            $game->actions()->where('type', GameActionType::Pass->value)->latest('sequence')->firstOrFail()
                ->payload['science_bonus_started'],
        );
        $this->assertSame([31, 29], array_column($game->state->players, 'victoryPoints'));
        $this->assertSame(2, $game->state->players[1]->resources->coins);
        $this->assertSame(1, $game->state->players[1]->resources->tools);
        $this->assertSame(2, $game->state->players[1]->resources->power->bowlTwo);
        $this->assertNull(
            $game->actions()->where('type', GameActionType::Pass->value)->latest('sequence')->firstOrFail()
                ->payload['final_resource_conversion'],
        );

        $finalScoring = $game->actions()
            ->where('type', GameActionType::PhaseCheckpoint->value)
            ->latest('sequence')
            ->firstOrFail()
            ->payload['final_scoring'];
        $this->assertSame([9, 9], array_column($finalScoring, 'victoryPoints'));
        $this->assertSame(['network'], array_column($finalScoring[0]['sources'], 'source'));
        $this->assertSame(['network'], array_column($finalScoring[1]['sources'], 'source'));
        $this->assertArrayNotHasKey(
            'final_scoring',
            $game->actions()->where('type', GameActionType::Pass->value)->latest('sequence')->firstOrFail()->payload,
        );
    }

    public function test_last_pass_sets_the_next_round_turn_order_and_starts_the_next_round(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $firstPlayerId = $game->state->players[0]->playerId;
        $secondPlayerId = $game->state->players[1]->playerId;

        $this->actingAs($firstUser)->post(route('games.pass', $game))->assertNoContent();
        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ])->assertNoContent();
        $this->actingAs($secondUser)->post(route('games.pass', $game))->assertNoContent();
        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::BuildGuild->value,
        ])->assertNoContent();

        $game->refresh();
        $this->assertSame(2, $game->state->round->number);
        $this->assertSame([$firstPlayerId, $secondPlayerId], $game->state->turnOrder);
        $this->assertSame([$firstPlayerId, $secondPlayerId], $game->state->round->passOrder);
        $this->assertSame([], $game->state->passedPlayerIds);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame($firstUser->id, $game->active_player_id);
        $this->assertSame(6, $game->actions()->count());
        $this->assertSame(1, $game->actions()->where('type', GameActionType::ScienceBonusPhase)->count());
        $this->assertSame(1, $game->actions()->where('type', GameActionType::IncomePhase)->count());

        $this->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page
                ->where('game.data.playerBoardStates.0.passOrder', null)
                ->where('game.data.playerBoardStates.1.passOrder', null),
        );
    }

    /** @return array{Game, User, User} */
    private function gameForPassing(): array
    {
        $firstUser = User::factory()->create();
        $secondUser = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $firstUser->id,
        ]);
        $firstPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $firstUser->id,
            'seat' => 1,
        ]);
        $secondPlayer = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $secondUser->id,
            'seat' => 2,
        ]);
        $setupPool = (new GameSetupPoolFactory())->createFromSeed(2, 'pass-test');
        $setupPool->roundScoringTiles[0] = RoundScoringTile::WorkshopLaw;
        $setupPool->availableRoundBonuses = [
            new RoundBonusOfferData(RoundBonus::RiverWorkshop, 2),
            new RoundBonusOfferData(RoundBonus::BuildGuild),
            new RoundBonusOfferData(RoundBonus::Coins),
        ];
        $game->update(['state' => new GameStateData(
            turnOrder: [$firstPlayer->id, $secondPlayer->id],
            round: new RoundStateData(
                phase: GamePhase::Actions,
                scoringTileId: $setupPool->roundScoringTiles[0]->value,
            ),
            players: [
                new GamePlayerStateData(
                    playerId: $firstPlayer->id,
                    userId: $firstUser->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Knowledge,
                ),
                new GamePlayerStateData(
                    playerId: $secondPlayer->id,
                    userId: $secondUser->id,
                    color: PlayerColor::Blue,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Swamp,
                    roundBonus: RoundBonus::PowerCoins,
                ),
            ],
            setupPool: $setupPool,
        )]);

        return [$game, $firstUser, $secondUser];
    }
}
