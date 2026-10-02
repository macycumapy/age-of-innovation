<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Setup;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Board\Services\LargestNetworkSizeCalculator;
use App\Domain\GameEngine\Economy\Services\PlayerIncomeCalculator;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Research\Enums\KnowledgeDiscipline;
use App\Domain\GameEngine\Setup\Data\PlanningBundleData;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PlanningTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_player_can_choose_planning_bundle(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'planning-selection-seed']);

        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'seat' => $index + 1,
            ]);
        }

        $this->actingAs($users[0])->post(route('games.start', $game));
        $game->refresh();

        $activeUser = $users->firstWhere('id', $game->active_player_id);
        $bundle = collect($game->state->setupPool->planningBundles)->first(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland !== TerrainType::Wasteland
                && $bundle->faction !== Faction::Lizards,
        );

        $this->assertInstanceOf(User::class, $activeUser);
        $this->assertInstanceOf(PlanningBundleData::class, $bundle);

        $this->actingAs($activeUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => TerrainType::Water->value,
            ])
            ->assertSessionHasErrors('homeland');

        $this->actingAs($activeUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $bundle->homeland->value,
            ])
            ->assertNoContent();

        $game->refresh();
        $player = $game->players()->whereBelongsTo($activeUser)->sole();

        $this->assertSame($bundle->homeland, $player->homeland);
        $this->assertSame($bundle->faction, $player->faction);
        $this->assertNotNull($player->color);
        $this->assertCount(7, $game->state->setupPool->planningBundles);
        $this->assertCount(1, $game->state->planningSelections);
        $this->assertSame($player->id, $game->state->planningSelections[0]->playerId);
        $this->assertCount(1, $game->state->players);
        $this->assertSame($player->id, $game->state->players[0]->playerId);
        $this->assertSame($bundle->roundBonus, $game->state->players[0]->roundBonus);
        $this->assertSame(15, $game->state->players[0]->resources->coins);
        $this->assertSame(12, $game->state->players[0]->resources->power->bowlOne
            + $game->state->players[0]->resources->power->bowlTwo
            + $game->state->players[0]->resources->power->bowlThree);
        $this->assertNotSame($activeUser->id, $game->active_player_id);

        $nextActiveUser = $users->firstWhere('id', $game->active_player_id);
        $this->assertInstanceOf(User::class, $nextActiveUser);

        $this->actingAs($nextActiveUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $bundle->homeland->value,
            ])
            ->assertSessionHasErrors('homeland');

        $game->refresh();
        $this->assertCount(7, $game->state->setupPool->planningBundles);
        $this->assertCount(1, $game->state->planningSelections);

        $this->get(route('games.show', $game))
            ->assertOk()
            ->assertInertia(
                fn (Assert $page) => $page
                    ->where('game.data.planningSelections.0.playerId', $player->id)
                    ->where(
                        'game.data.planningSelections.0.bundle.homeland',
                        $bundle->homeland->value,
                    )
                    ->where(
                        'game.data.planningSelections.0.bundle.faction',
                        $bundle->faction->value,
                    )
                    ->where(
                        'game.data.planningSelections.0.bundle.roundBonus',
                        $bundle->roundBonus->value,
                    )
                    ->where(
                        'game.data.players.'.($player->seat - 1).'.color',
                        $player->color->value,
                    )
                    ->has('game.data.planningBundles', 7)
                    ->has('game.data.planningBundleDescriptions.homelands', 8)
                    ->has('game.data.planningBundleDescriptions.factions', 12)
                    ->has('game.data.planningBundleDescriptions.roundBonuses', 10)
                    ->has('game.data.competencyDescriptions', 12)
                    ->has('game.data.innovationDescriptions', 18)
                    ->has('game.data.roundBonusDescriptions', 10)
                    ->has('game.data.palaceDescriptions', 17)
                    ->has('game.data.knowledgeDisciplineNames', 4)
                    ->where(
                        'game.data.competencyDescriptions.'.Competency::Competency01->value,
                        Competency::Competency01->description(),
                    )
                    ->where(
                        'game.data.innovationDescriptions.'.Innovation::DeusExMachina->value,
                        Innovation::DeusExMachina->description(),
                    )
                    ->where(
                        'game.data.roundBonusDescriptions.'.RoundBonus::Coins->value,
                        RoundBonus::Coins->description(),
                    )
                    ->where(
                        'game.data.palaceDescriptions.'.PalaceAbility::Palace01->value,
                        PalaceAbility::Palace01->description(),
                    )
                    ->where(
                        'game.data.knowledgeDisciplineNames.'.KnowledgeDiscipline::Banking->value,
                        KnowledgeDiscipline::Banking->displayName(),
                    )
                    ->where(
                        'game.data.planningBundleDescriptions.homelands.desert',
                        TerrainType::Desert->description(),
                    )
                    ->where(
                        'game.data.planningBundleDescriptions.factions.'.$bundle->faction->value,
                        $bundle->faction->description(),
                    )
                    ->where(
                        'game.data.planningBundleDescriptions.roundBonuses.'.$bundle->roundBonus->value,
                        $bundle->roundBonus->description(),
                    )
                    ->has('game.data.playerBoardStates', 1)
                    ->where('game.data.playerBoardStates.0.playerId', $player->id)
                    ->where(
                        'game.data.playerBoardStates.0.victoryPoints',
                        $game->state->players[0]->victoryPoints,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.roundBonus',
                        $game->state->players[0]->roundBonus->value,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.scholars',
                        $game->state->players[0]->resources->scholars,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.scholarDisciplineIds',
                        $game->state->players[0]->scholarDisciplineIds,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.scholarPoolSize',
                        $game->state->players[0]->scholarPoolSize,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.coins',
                        $game->state->players[0]->resources->coins,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.tools',
                        $game->state->players[0]->resources->tools,
                    )
                    ->where('game.data.playerBoardStates.0.books', [
                        'banking' => $game->state->players[0]
                            ->resources->books->banking,
                        'law' => $game->state->players[0]
                            ->resources->books->law,
                        'engineering' => $game->state->players[0]
                            ->resources->books->engineering,
                        'medicine' => $game->state->players[0]
                            ->resources->books->medicine,
                        'unassigned' => $game->state->players[0]
                            ->resources->books->unassigned,
                    ])
                    ->where(
                        'game.data.playerBoardStates.0.availableBridges',
                        3,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.competencyIds',
                        $game->state->players[0]->competencyIds,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.palaceId',
                        $game->state->players[0]->palaceId,
                    )
                    ->where('game.data.playerBoardStates.0.activeTownKeys', 0)
                    ->where('game.data.playerBoardStates.0.usedTownKeys', 0)
                    ->where('game.data.playerBoardStates.0.activeAnnexes', 0)
                    ->where('game.data.playerBoardStates.0.availableAnnexes', 0)
                    ->where(
                        'game.data.playerBoardStates.0.income',
                        PlayerIncomeCalculator::calculate(
                            $game->state->players[0],
                            $game->state->board,
                        )->resourceAmounts(),
                    )
                    ->where(
                        'game.data.playerBoardStates.0.shippingLevel',
                        $game->state->players[0]->shippingLevel,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.largestNetworkSize',
                        LargestNetworkSizeCalculator::calculate(
                            $game->state->players[0],
                            $game->state->board,
                        ),
                    )
                    ->where('game.data.playerBoardStates.0.terraformingLevel', 0)
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.banking',
                        $game->state->players[0]->knowledge->banking,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.law',
                        $game->state->players[0]->knowledge->law,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.engineering',
                        $game->state->players[0]->knowledge->engineering,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.knowledge.medicine',
                        $game->state->players[0]->knowledge->medicine,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.power.bowlOne',
                        $game->state->players[0]->resources->power->bowlOne,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.power.bowlTwo',
                        $game->state->players[0]->resources->power->bowlTwo,
                    )
                    ->where(
                        'game.data.playerBoardStates.0.power.bowlThree',
                        $game->state->players[0]->resources->power->bowlThree,
                    )
                    ->has('game.data.roundScoringTiles', 6)
                    ->where(
                        'game.data.finalRoundScoringTile',
                        $game->state->setupPool->additionalFinalRoundGoal->value,
                    )
                    ->has('game.data.bookActions', 3)
                    ->has('game.data.usedBookActionIds', 0)
                    ->has('game.data.powerActions', 6)
                    ->where('game.data.powerActions.0.id', 'build_bridge')
                    ->where('game.data.powerActions.0.cost', 3)
                    ->where(
                        'game.data.powerActions.0.description',
                        'Потратить 3 силы, чтобы построить мост.',
                    )
                    ->where('game.data.powerActions.0.isUsed', false)
                    ->where('game.data.powerActions.5.id', 'terraform_two_spades')
                    ->where('game.data.powerActions.5.cost', 6)
                    ->has('game.data.innovations', 6)
                    ->has('game.data.competencies', 12),
            );
    }

    public function test_planning_bundle_history_records_power_gained_from_starting_knowledge(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'starting-knowledge-power-history']);

        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'seat' => $index + 1,
            ]);
        }

        $this->actingAs($users[0])->post(route('games.start', $game));
        $game->refresh();

        $state = $game->state;
        $swampBundleIndex = collect($state->setupPool->planningBundles)->search(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland === TerrainType::Swamp,
        );

        $this->assertIsInt($swampBundleIndex);

        $state->setupPool->planningBundles[$swampBundleIndex] = new PlanningBundleData(
            TerrainType::Swamp,
            Faction::Navigators,
            RoundBonus::Coins,
        );
        $game->update(['state' => $state]);
        $activeUser = $users->firstWhere('id', $game->active_player_id);

        $this->assertInstanceOf(User::class, $activeUser);

        $this->actingAs($activeUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => TerrainType::Swamp->value,
            ])
            ->assertNoContent();

        $action = $game->actions()->where('type', GameActionType::ChoosePlanningBundle)->sole();

        $this->assertSame(1, $action->payload['gained_power']);
        $this->assertSame(2, $game->refresh()->state->players[0]->resources->power->bowlOne);
        $this->assertSame(10, $game->state->players[0]->resources->power->bowlTwo);
    }

    public function test_inactive_player_cannot_choose_planning_bundle(): void
    {
        $owner = User::factory()->create();
        $otherUser = User::factory()->create();
        $game = Game::factory()->create();
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $owner->id,
            'seat' => 1,
        ]);
        GamePlayer::factory()->ready()->create([
            'game_id' => $game->id,
            'user_id' => $otherUser->id,
            'seat' => 2,
        ]);

        $this->actingAs($owner)->post(route('games.start', $game));
        $game->refresh();

        $inactiveUser = $game->active_player_id === $owner->id ? $otherUser : $owner;
        $bundle = $game->state->setupPool->planningBundles[0];

        $this->actingAs($inactiveUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $bundle->homeland->value,
            ])
            ->assertForbidden();

        $this->assertCount(7, $game->refresh()->state->setupPool->planningBundles);
    }

    public function test_player_must_distribute_starting_resources_before_the_next_player_chooses(): void
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'starting-resources-seed']);

        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'seat' => $index + 1,
            ]);
        }

        $this->actingAs($users[0])->post(route('games.start', $game));
        $game->refresh();

        $activeUser = $users->firstWhere('id', $game->active_player_id);
        $state = $game->state;
        $bundle = collect($state->setupPool->planningBundles)->first(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland === TerrainType::Wasteland,
        );

        $this->assertInstanceOf(User::class, $activeUser);
        $this->assertInstanceOf(PlanningBundleData::class, $bundle);

        $bundle->faction = Faction::Lizards;
        $game->update(['state' => $state]);

        $this->actingAs($activeUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => TerrainType::Wasteland->value,
            ])
            ->assertNoContent();

        $game->refresh();
        $player = $game->players()->whereBelongsTo($activeUser)->sole();

        $this->assertSame($activeUser->id, $game->active_player_id);
        $this->assertSame(PendingInteractionType::ChooseStartingResources, $game->state->pendingInteraction?->type);
        $this->assertSame($player->id, $game->state->pendingInteraction?->playerId);
        $this->assertSame(1, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(2, $game->state->players[0]->knowledge->unassignedSteps);

        $this->post(route('games.rewards', $game))
            ->assertSessionHasErrors(['book_counts', 'knowledge_counts']);

        $this->assertNotNull($game->refresh()->state->pendingInteraction);

        $this->post(route('games.rewards', $game), [
            'book_counts' => [
                'banking' => 1,
                'law' => 1,
                'engineering' => 0,
                'medicine' => 0,
            ],
            'knowledge_counts' => [
                'banking' => 0,
                'law' => 2,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertSessionHasErrors('book_counts');

        $game->refresh();

        $this->assertNotNull($game->state->pendingInteraction);
        $this->assertSame(1, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(0, $game->state->players[0]->resources->books->banking);
        $this->assertSame(0, $game->state->players[0]->resources->books->law);
        $this->assertSame(2, $game->state->players[0]->knowledge->unassignedSteps);

        $this->post(route('games.rewards', $game), [
            'book_counts' => [
                'banking' => 1,
                'law' => 0,
                'engineering' => 0,
                'medicine' => 0,
            ],
            'knowledge_counts' => [
                'banking' => 1,
                'law' => 2,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertSessionHasErrors('knowledge_counts');

        $game->refresh();

        $this->assertSame(2, $game->state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(0, $game->state->players[0]->knowledge->banking);
        $this->assertSame(0, $game->state->players[0]->knowledge->law);

        $state = $game->state;
        $state->players[0]->knowledge->law = 2;
        $state->players[0]->resources->power->bowlOne = 1;
        $state->players[0]->resources->power->bowlTwo = 0;
        $state->players[0]->resources->power->bowlThree = 0;
        $game->update(['state' => $state]);

        $this->post(route('games.rewards', $game), [
            'book_counts' => [
                'banking' => 1,
                'law' => 0,
                'engineering' => 0,
                'medicine' => 0,
            ],
            'knowledge_counts' => [
                'banking' => 0,
                'law' => 2,
                'engineering' => 0,
                'medicine' => 0,
            ],
        ])->assertNoContent();

        $game->refresh();

        $this->assertNull($game->state->pendingInteraction);
        $this->assertSame(0, $game->state->players[0]->resources->books->unassigned);
        $this->assertSame(1, $game->state->players[0]->resources->books->banking);
        $this->assertSame(0, $game->state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(4, $game->state->players[0]->knowledge->law);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(1, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlThree);
        $this->assertNotSame($activeUser->id, $game->active_player_id);

        $inventorUser = $users->firstWhere('id', $game->active_player_id);
        $this->assertInstanceOf(User::class, $inventorUser);

        $state = $game->state;
        $inventorBundle = collect($state->setupPool->planningBundles)->first(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland === TerrainType::Forest,
        );
        $this->assertInstanceOf(PlanningBundleData::class, $inventorBundle);

        $inventorBundle->faction = Faction::Inventors;
        $otherCompetencies = collect($state->setupPool->competencies)
            ->reject(
                static fn (Competency|string $competency): bool => ($competency instanceof Competency
                    ? $competency->value
                    : $competency) === Competency::Competency01->value,
            )
            ->values();
        $state->setupPool->competencies = [
            ...$otherCompetencies->take(4)->all(),
            Competency::Competency01,
            ...$otherCompetencies->skip(4)->all(),
        ];
        $game->update(['state' => $state]);

        $this->actingAs($inventorUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $inventorBundle->homeland->value,
            ])
            ->assertNoContent();

        $game->refresh();
        $inventorPlayer = $game->players()->whereBelongsTo($inventorUser)->sole();
        $inventorState = collect($game->state->players)->firstWhere('playerId', $inventorPlayer->id);

        $this->assertInstanceOf(GamePlayerStateData::class, $inventorState);
        $this->assertSame([], $inventorState->competencyIds);
        $this->assertNotSame($inventorUser->id, $game->active_player_id);
    }

    #[DataProvider('immediateStartingCompetencyEffects')]
    public function test_inventors_receive_immediate_starting_competency_effects(
        Competency $competency,
        int $coins,
        int $tools,
        int $victoryPoints,
        int $unassignedSpades,
        int $availableAnnexes,
    ): void {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'immediate-competency-effects-seed']);

        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create([
                'game_id' => $game->id,
                'user_id' => $user->id,
                'seat' => $index + 1,
            ]);
        }

        $this->actingAs($users[0])->post(route('games.start', $game));
        $game->refresh();

        $activeUser = $users->firstWhere('id', $game->active_player_id);
        $this->assertInstanceOf(User::class, $activeUser);

        $state = $game->state;
        $bundle = collect($state->setupPool->planningBundles)->first(
            static fn (PlanningBundleData $bundle): bool => $bundle->homeland === TerrainType::Plains,
        );
        $this->assertInstanceOf(PlanningBundleData::class, $bundle);

        $bundle->faction = Faction::Inventors;
        $game->update(['state' => $state]);

        $this->actingAs($activeUser)
            ->post(route('games.planning-bundle.store', $game), [
                'homeland' => $bundle->homeland->value,
            ])
            ->assertNoContent();

        $game->refresh();
        $player = $game->players()->whereBelongsTo($activeUser)->sole();
        $playerStateBeforeCompetency = collect($game->state->players)->firstWhere('playerId', $player->id);
        $this->assertInstanceOf(GamePlayerStateData::class, $playerStateBeforeCompetency);

        $state = $game->state;

        if ($competency === Competency::Competency05) {
            $state->board = new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: $playerStateBeforeCompetency->homeland,
                    terrain: $playerStateBeforeCompetency->homeland,
                    adjacentHexIds: ['1:0'],
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['0:0'],
                ),
            ]);
        }

        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChooseCompetency,
            $player->id,
            [$competency->value],
        );
        $game->update([
            'active_player_id' => $activeUser->id,
            'state' => $state,
        ]);

        $this->post(route('games.rewards', $game), [
            'competency_id' => $competency->value,
        ])->assertNoContent();

        $game->refresh();
        $playerState = collect($game->state->players)->firstWhere('playerId', $player->id);
        $this->assertInstanceOf(GamePlayerStateData::class, $playerState);
        $this->assertSame($playerStateBeforeCompetency->resources->coins + $coins, $playerState->resources->coins);
        $this->assertSame($playerStateBeforeCompetency->resources->tools + $tools, $playerState->resources->tools);
        $this->assertSame($playerStateBeforeCompetency->victoryPoints + $victoryPoints, $playerState->victoryPoints);
        $this->assertSame($unassignedSpades, $playerState->unassignedSpades);
        $this->assertSame($availableAnnexes, $playerState->availableAnnexes);

        if ($competency === Competency::Competency05) {
            $this->assertSame(PendingInteractionType::SpendSpades, $game->state->pendingInteraction?->type);
            $this->assertSame(2, $game->state->pendingInteraction?->context['remainingSpades']);
            $this->assertSame(GamePhase::Setup->value, $game->state->pendingInteraction?->context['phase']);
            $this->assertTrue($game->state->pendingInteraction?->context['resumeStartingBuildingPlacement']);
            $this->assertSame($activeUser->id, $game->active_player_id);
        }
    }

    /** @return iterable<string, array{Competency, int, int, int, int, int}> */
    public static function immediateStartingCompetencyEffects(): iterable
    {
        yield 'competency_04 gives coins, a tool, and victory points' => [
            Competency::Competency04,
            2,
            1,
            5,
            0,
            0,
        ];
        yield 'competency_05 gives two unassigned spades' => [
            Competency::Competency05,
            0,
            0,
            0,
            2,
            0,
        ];
        yield 'competency_06 gives two available annexes' => [
            Competency::Competency06,
            0,
            0,
            0,
            0,
            2,
        ];
    }
}
