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
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PalaceActionsTest extends TestCase
{
    use RefreshDatabase;

    public static function multiplePalaceTownsProvider(): array
    {
        $cases = [];
        foreach ([PalaceAbility::Palace08, PalaceAbility::Palace14] as $palace) {
            foreach ([Faction::Blessed, Faction::Felines, Faction::Lizards] as $faction) {
                foreach ([TownTile::Coins, TownTile::Books, TownTile::Terraform] as $reward) {
                    $cases[$palace->value.' '.$faction->value.' '.$reward->value] = [$palace, $faction, $reward];
                }
            }
        }

        $cases['palace_14 declined first town'] = [PalaceAbility::Palace14, Faction::Blessed, TownTile::Coins, true];

        return $cases;
    }

    #[DataProvider('multiplePalaceTownsProvider')]
    public function test_all_palace_towns_are_offered_after_resolving_each_town_reward(
        PalaceAbility $palace,
        Faction $faction,
        TownTile $firstReward,
        bool $declineFirstTown = false,
    ): void {
        [$game, $user] = $this->gameForPalaceAction($palace, BuildingType::Palace);
        $state = $game->state;
        $player = $state->players[0];
        $player->faction = $faction;
        $player->palaceId = null;
        $state->availablePalaceIds = [$palace->value];
        $state->availableTownTileIds = [$firstReward->value, TownTile::Coins->value];
        foreach ([5, 15] as $offset) {
            $buildings = $palace === PalaceAbility::Palace08
                ? [BuildingType::Guild, BuildingType::Guild, BuildingType::Workshop, BuildingType::Workshop]
                : [BuildingType::Guild, BuildingType::Guild, null, BuildingType::Guild, BuildingType::Workshop];
            foreach ($buildings as $index => $building) {
                $q = $offset + $index;
                $terrain = $building === null ? TerrainType::Water : TerrainType::Forest;
                $state->board->hexes[] = new BoardHexStateData(
                    id: $q.':0',
                    q: $q,
                    r: 0,
                    initialTerrain: $terrain,
                    terrain: $terrain,
                    adjacentHexIds: array_values(array_filter([
                        $index > 0 ? ($q - 1).':0' : null,
                        $index < count($buildings) - 1 ? ($q + 1).':0' : null,
                        $q === 5 ? '5:1' : null,
                    ])),
                    building: $building === null ? null : new BuildingStateData($building, $player->playerId),
                );
            }
        }
        foreach (range(1, 5) as $r) {
            $state->board->hexes[] = new BoardHexStateData(
                id: '5:'.$r,
                q: 5,
                r: $r,
                initialTerrain: TerrainType::Mountain,
                terrain: TerrainType::Mountain,
                adjacentHexIds: ['5:0'],
            );
            $state->board->hexes[1]->adjacentHexIds[] = '5:'.$r;
        }
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChoosePalace,
            $player->playerId,
            [$palace->value],
            ['builtHexId' => '0:0', 'powerOffersResolved' => true],
        );
        $game->update(['state' => $state]);
        $game->actions()->create([
            'sequence' => 1, 'type' => \App\Domain\GameEngine\Enums\GameActionType::PhaseCheckpoint,
            'payload' => [
                'game' => [
                    'status' => GameStatus::Active->value, 'phase' => GamePhase::Actions->value,
                    'round' => 1, 'active_game_player_id' => $player->playerId, 'version' => 0,
                    'state' => $state->toArray(),
                ],
                'players' => [['id' => $player->playerId]],
            ],
            'events' => [], 'state_version_before' => 0, 'state_version_after' => 0,
        ]);
        $this->actingAs($user);
        $this->post(route('games.palace-choice', $game), ['palace_id' => $palace->value])->assertNoContent();
        $game->refresh();
        $this->assertCount($palace === PalaceAbility::Palace14 ? 2 : 1, $game->state->pendingInteractionQueue);
        if ($game->state->pendingInteraction?->type === PendingInteractionType::ChoosePalaceBooks) {
            $this->post(route('games.rewards', $game), [
                'book_counts' => ['banking' => $game->state->pendingInteraction->context['bookCount'], 'law' => 0, 'engineering' => 0, 'medicine' => 0],
            ])->assertNoContent();
        }
        foreach ([$firstReward, TownTile::Coins] as $townIndex => $reward) {
            $game->refresh();
            if ($palace === PalaceAbility::Palace14) {
                $this->assertSame(PendingInteractionType::OfferPalaceWaterTown, $game->state->pendingInteraction?->type);
                $this->post(route('games.town.palace-water', $game), [
                    'accept' => ! ($declineFirstTown && $townIndex === 0), 'water_hex_id' => $townIndex === 0 ? '7:0' : '17:0',
                ])->assertNoContent();
                $game->refresh();
                if ($declineFirstTown && $townIndex === 0) {
                    $this->assertSame(['17:0'], $game->state->pendingInteraction?->optionIds);
                    $this->post(route('games.current-turn.restart', $game))->assertNoContent();
                    $game->refresh();
                    $this->assertSame(['7:0'], $game->state->pendingInteraction?->optionIds);
                    $this->assertCount(1, $game->state->pendingInteractionQueue);
                    $this->post(route('games.town.palace-water', $game), ['accept' => false])->assertNoContent();

                    continue;
                }
            }
            $this->assertSame(PendingInteractionType::ChooseTown, $game->state->pendingInteraction?->type);
            $this->assertCount($declineFirstTown ? 0 : $townIndex, $game->state->players[0]->townTileIds);
            $this->post(route('games.town', $game), ['town_tile' => $reward->value])->assertNoContent();
            for ($step = 0; $step < 15; $step++) {
                $game->refresh();
                $interaction = $game->state->pendingInteraction;
                if ($interaction === null || in_array($interaction->type, [PendingInteractionType::ChooseTown, PendingInteractionType::OfferPalaceWaterTown], true)) {
                    break;
                }
                if ($interaction->type === PendingInteractionType::ChooseTownBooks || $interaction->type === PendingInteractionType::ChooseFelineTownBonus) {
                    $this->post(route('games.rewards', $game), [
                        'book_counts' => ['banking' => $interaction->context['bookCount'], 'law' => 0, 'engineering' => 0, 'medicine' => 0],
                        ...($interaction->type === PendingInteractionType::ChooseFelineTownBonus
                            ? ['knowledge_counts' => ['banking' => 0, 'law' => 3, 'engineering' => 0, 'medicine' => 0]] : []),
                    ])->assertNoContent();
                } elseif ($interaction->type === PendingInteractionType::SpendSpades) {
                    $this->post(route('games.starting-spade.store', $game), ['hex_id' => $interaction->optionIds[0]])->assertNoContent();
                    $this->delete(route('games.starting-spade.destroy', $game))->assertNoContent();
                    $this->post(route('games.starting-spade.store', $game), ['hex_id' => $interaction->optionIds[0]])->assertNoContent();
                    $this->post(route('games.starting-spade.finish', $game))->assertNoContent();
                } else {
                    $this->assertSame(PendingInteractionType::BuildWorkshopAfterTerraforming, $interaction->type);
                    $this->post(route('games.terraform-workshop', $game), ['build' => false])->assertNoContent();
                }
            }
        }
        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame($declineFirstTown ? [TownTile::Coins->value] : [$firstReward->value, TownTile::Coins->value], $game->state->players[0]->townTileIds);
        $this->assertSame([], $game->state->pendingInteractionQueue);
        if ($declineFirstTown) {
            $state = $game->state;
            app(\App\Domain\GameEngine\Towns\Actions\CreateTownChoiceAfterBuildingAction::class)->execute($state, $state->players[0], '0:0', powerOffersResolved: true);
            $this->assertNull($state->pendingInteraction);
        }
        $expectedBoard = $game->state->board->toArray();
        $expectedPlayer = $game->state->players[0]->toArray();
        app(\App\Domain\GameEngine\History\Actions\ReplayGameHistoryAction::class)->execute($game, $game->actions()->orderBy('sequence')->get());
        $game->refresh();
        $this->assertNull($game->state->pendingInteraction);
        $this->assertEquals($expectedBoard, $game->state->board->toArray());
        $this->assertEquals($expectedPlayer, $game->state->players[0]->toArray());
    }

    public static function palaceTownThresholdProvider(): array
    {
        return [
            'six power away from palace' => [PalaceAbility::Palace08, BuildingType::Guild, null, true],
            'below six power' => [PalaceAbility::Palace08, BuildingType::Workshop, null, false],
            'another palace keeps seven power threshold' => [PalaceAbility::Palace01, BuildingType::Guild, null, false],
            'existing town is excluded' => [PalaceAbility::Palace08, BuildingType::Guild, 'existing-town', false],
        ];
    }

    #[DataProvider('palaceTownThresholdProvider')]
    public function test_palace_eight_checks_groups_outside_the_palace_component(
        PalaceAbility $palace,
        BuildingType $secondBuilding,
        ?string $townId,
        bool $offersTown,
    ): void {
        [$game, $user] = $this->gameForPalaceAction($palace, BuildingType::Palace);
        $state = $game->state;
        $player = $state->players[0];
        $player->palaceId = null;
        $state->availablePalaceIds = [$palace->value];
        $state->availableTownTileIds = [TownTile::Coins->value];
        foreach ([BuildingType::Guild, $secondBuilding, BuildingType::Workshop, BuildingType::Workshop] as $index => $building) {
            $state->board->hexes[] = new BoardHexStateData(
                id: ($index + 5).':0',
                q: $index + 5,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                adjacentHexIds: array_values(array_filter([
                    $index > 0 ? ($index + 4).':0' : null,
                    $index < 3 ? ($index + 6).':0' : null,
                ])),
                building: new BuildingStateData($building, $player->playerId),
                townId: $townId,
            );
        }
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChoosePalace,
            $player->playerId,
            [$palace->value],
            ['builtHexId' => '0:0', 'powerOffersResolved' => true],
        );
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.palace-choice', $game), ['palace_id' => $palace->value])->assertNoContent();
        $game->refresh();
        $this->assertSame($palace->value, $game->state->players[0]->palaceId);
        if (! $offersTown) {
            $this->assertNull($game->state->pendingInteraction);

            return;
        }

        $this->assertSame(PendingInteractionType::ChooseTown, $game->state->pendingInteraction?->type);
        $this->assertEqualsCanonicalizing(['5:0', '6:0', '7:0', '8:0'], $game->state->pendingInteraction?->context['townHexIds']);
        $this->assertSame([], $game->state->players[0]->townTileIds);
        $this->post(route('games.town', $game), ['town_tile' => TownTile::Coins->value])->assertNoContent();
        $game->refresh();
        $this->assertSame([TownTile::Coins->value], $game->state->players[0]->townTileIds);
        $this->assertNull($game->state->board->hexes[0]->townId);
        $this->assertNotNull($game->state->board->hexes[1]->townId);
    }

    public function test_neutral_palace_does_not_score_palace_seventeen_bonus(): void
    {
        [$game] = $this->gameForPalaceAction(PalaceAbility::Palace17, BuildingType::Palace);
        $state = $game->state;
        $state->board->hexes[0]->building->isNeutral = true;
        $state->round->scoringTileId = RoundScoringTile::PalaceUniversityBanking->value;
        $player = $state->players[0];
        $initialPoints = $player->victoryPoints;

        $bonuses = app(\App\Domain\GameEngine\Board\Actions\ApplyBuildingBonusesAction::class)->execute(
            $state,
            $player,
            $state->board->hexes[0],
            BuildingType::Palace,
        );

        $this->assertSame(5, $bonuses['victoryPoints']);
        $this->assertSame($initialPoints + 5, $player->victoryPoints);
        $this->assertSame([
            ['source' => 'round_scoring', 'id' => RoundScoringTile::PalaceUniversityBanking->value, 'points' => 5],
        ], $bonuses['sources']);
    }

    public function test_palace_fourteen_offers_a_water_town_outside_the_palace_component(): void
    {
        [$game, $user] = $this->gameForPalaceAction(PalaceAbility::Palace14, BuildingType::Palace);
        $state = $game->state;
        $player = $state->players[0];
        $player->palaceId = null;
        $state->availablePalaceIds = [PalaceAbility::Palace14->value];
        $state->availableTownTileIds = [TownTile::Coins->value];
        foreach ([BuildingType::Guild, BuildingType::Guild, null, BuildingType::Guild, BuildingType::Workshop] as $index => $building) {
            $terrain = $building === null ? TerrainType::Water : TerrainType::Forest;
            $state->board->hexes[] = new BoardHexStateData(
                id: ($index + 5).':0',
                q: $index + 5,
                r: 0,
                initialTerrain: $terrain,
                terrain: $terrain,
                adjacentHexIds: array_values(array_filter([
                    $index > 0 ? ($index + 4).':0' : null,
                    $index < 4 ? ($index + 6).':0' : null,
                ])),
                building: $building === null ? null : new BuildingStateData($building, $player->playerId),
            );
        }
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChoosePalace,
            $player->playerId,
            [PalaceAbility::Palace14->value],
            ['builtHexId' => '0:0', 'powerOffersResolved' => true],
        );
        $game->update(['state' => $state]);

        $this->actingAs($user)->post(route('games.palace-choice', $game), ['palace_id' => PalaceAbility::Palace14->value])->assertNoContent();
        $game->refresh();
        $this->assertSame(PendingInteractionType::ChoosePalaceBooks, $game->state->pendingInteraction?->type);
        $this->post(route('games.rewards', $game), [
            'book_counts' => ['banking' => $game->state->players[0]->resources->books->unassigned, 'law' => 0, 'engineering' => 0, 'medicine' => 0],
        ])->assertNoContent();
        $game->refresh();
        $this->assertSame(PendingInteractionType::OfferPalaceWaterTown, $game->state->pendingInteraction?->type);
        $this->assertSame(['7:0'], $game->state->pendingInteraction?->optionIds);
        $this->assertSame([], $game->state->players[0]->townTileIds);
        $this->post(route('games.town.palace-water', $game), ['accept' => true, 'water_hex_id' => '7:0'])->assertNoContent();
        $game->refresh();
        $this->assertSame(PendingInteractionType::ChooseTown, $game->state->pendingInteraction?->type);
        $this->assertEqualsCanonicalizing(['5:0', '6:0', '7:0', '8:0', '9:0'], $game->state->pendingInteraction?->context['townHexIds']);
        $this->post(route('games.town', $game), ['town_tile' => TownTile::Coins->value])->assertNoContent();
        $game->refresh();
        $this->assertSame([TownTile::Coins->value], $game->state->players[0]->townTileIds);
        $this->assertNull($game->state->board->hexes[0]->townId);
    }

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
