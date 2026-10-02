<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\GameEngine;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Data\RewardDistributionOptionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Scoring\Enums\RoundScoringTile;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RewardDistributionSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_town_book_distributions(): void
    {
        $state = new GameStateData(
            schemaVersion: 4,
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseTownBooks,
                1,
                [],
                ['bookCount' => 2],
            ),
        );
        $state->players[0]->resources->books->unassigned = 2;

        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof RewardDistributionOptionData,
        ));

        $this->assertCount(10, $options);
        $this->assertSame(GameActionOptionType::DistributeRewards, $options[0]->type());
        $this->assertSame([
            'banking' => 0,
            'law' => 0,
            'engineering' => 0,
            'medicine' => 2,
        ], $options[0]->bookCounts);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(2, $state->players[0]->resources->books->unassigned);
        $this->assertSame(0, $state->players[0]->resources->books->medicine);
        $this->assertSame(0, $simulation->state->players[0]->resources->books->unassigned);
        $this->assertSame(2, $simulation->state->players[0]->resources->books->medicine);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    public function test_it_enumerates_and_simulates_feline_town_bonus_distributions(): void
    {
        $state = new GameStateData(
            schemaVersion: 4,
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Red,
                faction: Faction::Felines,
                homeland: TerrainType::Desert,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseFelineTownBonus,
                1,
                [],
                ['bookCount' => 1, 'knowledgeStepCount' => 3],
            ),
        );
        $state->players[0]->resources->books->unassigned = 1;
        $state->players[0]->knowledge->unassignedSteps = 3;

        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof RewardDistributionOptionData,
        ));

        $this->assertCount(80, $options);
        $this->assertSame(1, $options[0]->bookCounts['medicine']);
        $this->assertSame(3, $options[0]->knowledgeCounts['medicine']);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(1, $state->players[0]->resources->books->unassigned);
        $this->assertSame(3, $state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(0, $state->players[0]->knowledge->medicine);
        $this->assertSame(0, $simulation->state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(1, $simulation->state->players[0]->resources->books->medicine);
        $this->assertSame(3, $simulation->state->players[0]->knowledge->medicine);
        $this->assertNotNull($simulation->state->turnStartSnapshot);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    #[DataProvider('developmentRewardInteractionTypes')]
    public function test_it_enumerates_and_simulates_development_reward_books(
        PendingInteractionType $interactionType,
    ): void {
        $state = new GameStateData(
            schemaVersion: 4,
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                $interactionType,
                1,
                [],
                ['bookCount' => 2, 'builtHexId' => '0:0'],
            ),
        );
        $state->players[0]->resources->books->unassigned = 2;

        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof RewardDistributionOptionData,
        ));

        $this->assertCount(10, $options);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(2, $state->players[0]->resources->books->unassigned);
        $this->assertSame(0, $simulation->state->players[0]->resources->books->unassigned);
        $this->assertSame(2, $simulation->state->players[0]->resources->books->medicine);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    /** @return array<string, array{PendingInteractionType}> */
    public static function developmentRewardInteractionTypes(): array
    {
        return [
            'shipping' => [PendingInteractionType::ChooseShippingBooks],
            'terraforming' => [PendingInteractionType::ChooseTerraformingBooks],
            'palace' => [PendingInteractionType::ChoosePalaceBooks],
        ];
    }

    public function test_it_enumerates_and_simulates_innovation_reward_distributions(): void
    {
        $state = new GameStateData(
            schemaVersion: 4,
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseInnovationReward,
                1,
                [],
                ['bookCount' => 1, 'knowledgeStepCount' => 3],
            ),
        );
        $state->players[0]->resources->books->unassigned = 1;
        $state->players[0]->knowledge->unassignedSteps = 3;

        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof RewardDistributionOptionData,
        ));

        $this->assertCount(80, $options);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(1, $state->players[0]->resources->books->unassigned);
        $this->assertSame(3, $state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(1, $simulation->state->players[0]->resources->books->medicine);
        $this->assertSame(3, $simulation->state->players[0]->knowledge->medicine);
        $this->assertSame(0, $simulation->state->players[0]->knowledge->unassignedSteps);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    public function test_it_enumerates_and_simulates_science_bonus_book_distributions(): void
    {
        $state = new GameStateData(
            schemaVersion: 4,
            turnOrder: [1, 2],
            players: [
                new GamePlayerStateData(
                    playerId: 1,
                    userId: 10,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(),
                ),
                new GamePlayerStateData(
                    playerId: 2,
                    userId: 20,
                    color: PlayerColor::Red,
                    faction: Faction::Inventors,
                    homeland: TerrainType::Desert,
                    roundBonus: RoundBonus::PowerCoins,
                    resources: new PlayerResourcesData(),
                ),
            ],
            round: new RoundStateData(
                phase: GamePhase::ScienceBonus,
                scoringTileId: RoundScoringTile::GuildLaw->value,
                scienceBonusTurnIndex: 1,
            ),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseScienceBonusBooks,
                1,
                [],
                ['bookCount' => 3],
            ),
        );
        $state->players[0]->resources->books->unassigned = 3;
        $state->players[1]->knowledge->law = 3;

        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof RewardDistributionOptionData,
        ));

        $this->assertCount(20, $options);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(0, $state->players[0]->resources->books->medicine);
        $this->assertSame(3, $state->players[0]->resources->books->unassigned);
        $this->assertSame(3, $simulation->state->players[0]->resources->books->medicine);
        $this->assertSame(0, $simulation->state->players[0]->resources->books->unassigned);
        $this->assertSame(2, $simulation->nextActivePlayerId);
        $this->assertNotNull($simulation->state->pendingInteraction);
        $this->assertSame(
            PendingInteractionType::ChooseScienceBonusBooks,
            $simulation->state->pendingInteraction->type,
        );
        $this->assertSame(2, $simulation->state->pendingInteraction->playerId);
        $this->assertSame(1, $simulation->state->pendingInteraction->context['bookCount']);
        $this->assertSame(1, $simulation->state->players[1]->resources->books->unassigned);
    }

    public function test_it_enumerates_and_simulates_income_resource_distributions(): void
    {
        $state = new GameStateData(
            schemaVersion: 4,
            turnOrder: [1, 2],
            players: [
                new GamePlayerStateData(
                    playerId: 1,
                    userId: 10,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(),
                ),
                new GamePlayerStateData(
                    playerId: 2,
                    userId: 20,
                    color: PlayerColor::Red,
                    faction: Faction::Inventors,
                    homeland: TerrainType::Desert,
                    roundBonus: RoundBonus::PowerCoins,
                    resources: new PlayerResourcesData(),
                ),
            ],
            round: new RoundStateData(
                phase: GamePhase::Income,
                incomeTurnIndex: 1,
                incomeOrder: [1, 2],
            ),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseStartingResources,
                1,
                [],
                ['bookCount' => 1, 'knowledgeStepCount' => 2],
            ),
        );
        $state->players[0]->resources->books->unassigned = 1;
        $state->players[0]->knowledge->unassignedSteps = 2;
        $state->players[1]->resources->books->unassigned = 1;

        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof RewardDistributionOptionData,
        ));

        $this->assertCount(40, $options);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(1, $state->players[0]->resources->books->unassigned);
        $this->assertSame(2, $state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(0, $simulation->state->players[0]->resources->books->unassigned);
        $this->assertSame(0, $simulation->state->players[0]->knowledge->unassignedSteps);
        $this->assertSame(2, $simulation->nextActivePlayerId);
        $this->assertSame(2, $simulation->state->pendingInteraction?->playerId);
    }

    public function test_setup_resource_simulation_has_no_next_user_until_the_next_player_is_created(): void
    {
        $state = new GameStateData(
            schemaVersion: 4,
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(),
            )],
            round: new RoundStateData(phase: GamePhase::Setup),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseStartingResources,
                1,
                [],
                ['bookCount' => 1, 'knowledgeStepCount' => 0],
            ),
        );
        $state->players[0]->resources->books->unassigned = 1;

        $options = app(GameActionOptionFinder::class)->execute($state, 1);
        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertCount(4, $options);
        $this->assertNull($simulation->nextActivePlayerId);
        $this->assertNull($simulation->state->pendingInteraction);
    }
}
