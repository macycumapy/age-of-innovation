<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\PlayerAbilities;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
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
use Tests\TestCase;

class PalaceActionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_palace_competency_choice_excludes_owned_competencies(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace05, BuildingType::Palace);
        $state = $game->state;
        $player = $state->players[0];
        $player->palaceId = null;
        $player->competencyIds = [Competency::Competency07->value];
        $state->availablePalaceIds = [PalaceAbility::Palace05->value];
        $state->availableCompetencyIds = [Competency::Competency07->value, Competency::Competency07->value, Competency::Competency04->value, Competency::Competency04->value];
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChoosePalace,
            $player->playerId,
            [PalaceAbility::Palace05->value],
            ['builtHexId' => '0:0', 'powerOffersResolved' => true],
        );
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.palace-choice', $game), ['palace_id' => PalaceAbility::Palace05->value])->assertNoContent();
        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseCompetency, $game->state->pendingInteraction?->type);
        $this->assertSame([Competency::Competency04->value], $game->state->pendingInteraction?->optionIds);
        $this->post(route('games.rewards', $game), ['competency_id' => Competency::Competency07->value])->assertSessionHasErrors('competency_id');
        $this->post(route('games.rewards', $game), ['competency_id' => Competency::Competency04->value])->assertNoContent();
        $this->assertSame([Competency::Competency07->value, Competency::Competency04->value], $game->fresh()->state->players[0]->competencyIds);
    }

    public function test_palace_does_not_offer_competency_choice_when_only_owned_competencies_remain(): void
    {
        [$game] = $this->gameForPalaceAction(PalaceAbility::Palace05, BuildingType::Palace);
        $state = $game->state;
        $player = $state->players[0];
        $player->palaceId = null;
        $player->competencyIds = [Competency::Competency07->value];
        $state->availableCompetencyIds = [Competency::Competency07->value];
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChoosePalace,
            $player->playerId,
            [PalaceAbility::Palace05->value],
            ['builtHexId' => '0:0', 'powerOffersResolved' => true],
        );

        app(\App\Domain\GameEngine\PlayerAbilities\Actions\ApplyChoosePalaceAction::class)->execute($state, $player, PalaceAbility::Palace05);

        $this->assertNull($state->pendingInteraction);
        $this->assertSame(PalaceAbility::Palace05->value, $player->palaceId);
    }

    public function test_palace_resource_action_can_be_confirmed_only_once_and_restarted(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace01);

        $this->actingAs($user)->post(route('games.palace-action', $game))
            ->assertNoContent();
        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->resources->tools);
        $this->assertContains(PalaceAbility::Palace01->specialActionId(), $game->state->players[0]->usedSpecialActionIds);

        $this->post(route('games.palace-action', $game))->assertSessionHasErrors('palace');
        $this->post(route('games.current-turn.restart', $game));
        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame([], $game->state->players[0]->usedSpecialActionIds);
    }

    public function test_palace_knowledge_action_distributes_two_steps_between_disciplines(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace06);
        $state = $game->state;
        $state->round->scoringTileId = RoundScoringTile::KnowledgeMedicine->value;
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.palace-action', $game))
            ->assertSessionHasErrors('knowledge_steps');
        $this->post(route('games.palace-action', $game), [
            'knowledge_steps' => [
                KnowledgeDiscipline::Banking->value => 0,
                KnowledgeDiscipline::Law->value => 1,
                KnowledgeDiscipline::Engineering->value => 0,
                KnowledgeDiscipline::Medicine->value => 1,
            ],
        ]);
        $game->refresh();
        $this->assertSame(1, $game->state->players[0]->knowledge->law);
        $this->assertSame(1, $game->state->players[0]->knowledge->medicine);
        $this->assertSame(22, $game->state->players[0]->victoryPoints);
        $this->assertSame(2, $game->actions()->sole()->payload['victory_points']);
        $this->assertSame([
            KnowledgeDiscipline::Law->value,
            KnowledgeDiscipline::Medicine->value,
        ], $game->actions()->sole()->payload['knowledge_disciplines']);
    }

    public function test_palace_knowledge_action_may_apply_both_steps_to_one_discipline(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace06);

        $this->actingAs($user)->post(route('games.palace-action', $game), [
            'knowledge_steps' => [KnowledgeDiscipline::Engineering->value => 2],
        ]);
        $game->refresh();

        $this->assertSame(2, $game->state->players[0]->knowledge->engineering);
    }

    public function test_palace_knowledge_action_scores_only_steps_that_were_actually_advanced(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace06);
        $state = $game->state;
        $state->round->scoringTileId = RoundScoringTile::KnowledgeMedicine->value;
        $state->players[0]->knowledge->banking = 7;
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.palace-action', $game), [
            'knowledge_steps' => [
                KnowledgeDiscipline::Banking->value => 1,
                KnowledgeDiscipline::Medicine->value => 1,
            ],
        ]);
        $game->refresh();

        $this->assertSame(7, $game->state->players[0]->knowledge->banking);
        $this->assertSame(1, $game->state->players[0]->knowledge->medicine);
        $this->assertSame(21, $game->state->players[0]->victoryPoints);
        $this->assertSame(1, $game->actions()->sole()->payload['victory_points']);
    }

    public function test_palace_spade_action_starts_terraforming_with_two_spades(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace02);
        $state = $game->state;
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

        $this->actingAs($user)->post(route('games.palace-action', $game))
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(2, $game->state->players[0]->unassignedSpades);
        $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);
        $this->assertSame(['1:0'], $game->state->pendingInteraction?->optionIds);
        $this->assertSame(2, $game->state->pendingInteraction?->context['remainingSpades']);
    }

    public function test_palace_can_replace_a_school_with_a_guild(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace03, BuildingType::School);

        $this->actingAs($user)->post(route('games.palace-action', $game), ['hex_id' => '0:0']);
        $game->refresh();
        $this->assertSame(BuildingType::Guild, $game->state->board->hexes[0]->building?->type);
        $this->assertSame(23, $game->state->players[0]->victoryPoints);
        $this->assertSame(1, $game->state->players[0]->resources->tools);
    }

    public function test_palace_can_upgrade_a_workshop_to_a_guild_for_free(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace04, BuildingType::Workshop);
        $this->actingAs($user)->post(route('games.palace-action', $game), ['hex_id' => '0:0']);
        $game->refresh();
        $this->assertSame(BuildingType::Guild, $game->state->board->hexes[0]->building?->type);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertSame(0, $game->state->players[0]->resources->coins);
    }

    public function test_palace_can_grant_coins_and_a_selected_book(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace13);
        $this->actingAs($user)->post(route('games.palace-action', $game), ['discipline' => KnowledgeDiscipline::Law->value]);
        $game->refresh();
        $this->assertSame(3, $game->state->players[0]->resources->coins);
        $this->assertSame(1, $game->state->players[0]->resources->books->law);
    }

    /** @return array{Game, User} */
    private function gameForPalaceAction(PalaceAbility $palace, ?BuildingType $building = null): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->create(['status' => GameStatus::Active, 'phase' => GamePhase::Actions, 'active_player_id' => $user->id]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $hexes = $building === null ? [] : [new BoardHexStateData(
            id: '0:0',
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            building: new BuildingStateData($building, $player->id),
        )];
        $game->update(['state' => new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: $hexes),
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                palaceId: $palace->value,
            )],
        )]);

        return [$game, $user];
    }
}
