<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Turns;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Data\RoundBonusOfferData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
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

class ScienceBonusPhaseTest extends TestCase
{
    use RefreshDatabase;

    public function test_players_choose_science_bonus_books_in_pass_order_before_the_next_round(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $state = $game->state;
        $state->round->scoringTileId = RoundScoringTile::GuildLaw->value;
        $state->setupPool->roundScoringTiles[0] = RoundScoringTile::GuildLaw;
        $state->players[0]->knowledge->law = 6;
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game));
        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ]);
        $this->actingAs($secondUser)->post(route('games.pass', $game));
        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::BuildGuild->value,
        ]);

        $game->refresh();
        $this->assertSame(GamePhase::ScienceBonus, $game->phase);
        $this->assertSame($firstUser->id, $game->active_player_id);
        $this->assertSame(PendingInteractionType::ChooseScienceBonusBooks, $game->state->pendingInteraction?->type);
        $this->assertSame(3, $game->state->pendingInteraction?->context['bookCount']);
        $this->assertSame(3, $game->state->players[0]->resources->books->unassigned);

        $this->actingAs($firstUser)->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 3, 'law' => 0, 'engineering' => 0, 'medicine' => 0],
        ])->assertNoContent()->assertSessionHasNoErrors();

        $game->refresh();
        $this->assertSame(3, $game->state->players[0]->resources->books->banking);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(0, $game->state->players[0]->resources->books->medicine);
        $this->assertSame($secondUser->id, $game->active_player_id);
        $this->assertSame(PendingInteractionType::ChooseScienceBonusBooks, $game->state->pendingInteraction?->type);
        $this->assertSame(1, $game->state->pendingInteraction?->context['bookCount']);
        $this->assertSame(1, $game->state->players[1]->resources->books->unassigned);

        $this->actingAs($secondUser)->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => 0, 'law' => 1, 'engineering' => 0, 'medicine' => 0],
        ])->assertNoContent()->assertSessionHasNoErrors();

        $game->refresh();
        $this->assertSame(1, $game->state->players[1]->resources->books->law);
        $this->assertSame(0, $game->state->players[1]->resources->books->unassigned);
        $this->assertSame(2, $game->state->round->number);
        $this->assertSame(GamePhase::Actions, $game->phase);
        $this->assertSame($firstUser->id, $game->active_player_id);
    }

    public function test_automatic_science_bonuses_are_applied_before_the_next_round(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $state = $game->state;
        $state->round->scoringTileId = RoundScoringTile::PalaceUniversityBanking->value;
        $state->setupPool->roundScoringTiles[0] = RoundScoringTile::PalaceUniversityBanking;
        $state->players[0]->knowledge->banking = 4;
        $state->players[1]->knowledge->banking = 6;
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game));
        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ]);
        $this->actingAs($secondUser)->post(route('games.pass', $game));
        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::BuildGuild->value,
        ]);

        $game->refresh();
        $this->assertSame(4, $game->state->players[0]->resources->tools);
        $this->assertSame(5, $game->state->players[1]->resources->tools);
        $this->assertSame(2, $game->state->round->number);
        $this->assertSame(GamePhase::Actions, $game->phase);
    }

    public function test_science_phase_records_engineering_coin_rewards_in_phase_history(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $state = $game->state;
        $state->round->scoringTileId = RoundScoringTile::SpadeEngineering->value;
        $state->setupPool->roundScoringTiles[0] = RoundScoringTile::SpadeEngineering;
        $state->players[0]->knowledge->engineering = 7;
        $state->players[1]->knowledge->engineering = 2;
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game));
        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ]);
        $this->actingAs($secondUser)->post(route('games.pass', $game));
        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::BuildGuild->value,
        ])->assertNoContent();

        $receipts = $game->actions()
            ->where('type', GameActionType::ScienceBonusPhase->value)
            ->latest('sequence')
            ->firstOrFail()
            ->payload['science_bonus_receipts'];

        $this->assertArrayNotHasKey(
            'science_bonus_receipts',
            $game->actions()->where('type', GameActionType::ChooseRoundBonus)->latest('sequence')->firstOrFail()->payload,
        );

        $this->assertSame([7, 2], array_column($receipts, 'knowledge_level'));
        $this->assertSame([10, 5], array_column($receipts, 'coins'));
        $this->assertSame(
            [KnowledgeDiscipline::Engineering->value, KnowledgeDiscipline::Engineering->value],
            array_column($receipts, 'discipline'),
        );
    }

    public function test_player_can_confirm_or_rollback_a_science_bonus_spade(): void
    {
        [$game, $firstUser, $secondUser] = $this->gameForPassing();
        $state = $game->state;
        $state->round->scoringTileId = RoundScoringTile::GuildMedicine->value;
        $state->setupPool->roundScoringTiles[0] = RoundScoringTile::GuildMedicine;
        $state->players[0]->knowledge->medicine = 4;
        $state->board = new BoardStateData(hexes: [
            new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                adjacentHexIds: ['1:0'],
                building: new BuildingStateData(BuildingType::Workshop, $state->players[0]->playerId),
            ),
            new BoardHexStateData(
                id: '1:0',
                q: 1,
                r: 0,
                initialTerrain: TerrainType::Mountain,
                terrain: TerrainType::Mountain,
                adjacentHexIds: ['0:0'],
            ),
        ]);
        $game->update(['state' => $state]);

        $this->actingAs($firstUser)->post(route('games.pass', $game));
        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::RiverWorkshop->value,
        ]);
        $this->actingAs($secondUser)->post(route('games.pass', $game));
        $this->post(route('games.round-bonus-choice', $game), [
            'round_bonus' => RoundBonus::BuildGuild->value,
        ]);
        $game->refresh();
        $this->assertSame(GamePhase::ScienceBonus, $game->phase);
        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);

        $this->actingAs($firstUser)->post(route('games.paid-terraforming', $game), [
            'hex_id' => '1:0',
            'use_available' => false,
        ])->assertNoContent();
        $this->delete(route('games.starting-spade.destroy', $game));
        $game->refresh();
        $this->assertSame(TerrainType::Mountain, $game->state->board->hexes[1]->terrain);

        $this->post(route('games.starting-spade.store', $game), ['hex_id' => '1:0']);
        $this->post(route('games.starting-spade.finish', $game))->assertNoContent();
        $game->refresh();
        $this->assertSame(TerrainType::Forest, $game->state->board->hexes[1]->terrain);
        $this->assertSame(0, $game->state->players[0]->unassignedSpades);
        $this->assertSame(2, $game->state->round->number);
        $this->assertNull($game->state->turnStartSnapshot);
        $this->assertNull($game->state->round->turnStartVersion);
        $this->get(route('games.show', $game))->assertInertia(
            fn (Assert $page) => $page->where('game.data.canRestartCurrentTurn', false),
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
