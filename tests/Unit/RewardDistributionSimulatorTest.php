<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RewardDistributionOptionData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionOptionFinder;
use App\Domain\Game\Services\GameActionSimulator;
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
        $this->assertSame(10, $simulation->nextActiveUserId);
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

        $options = array_values(array_filter(
            app(GameActionOptionFinder::class)->execute($state, 1),
            static fn ($option): bool => $option instanceof RewardDistributionOptionData,
        ));

        $this->assertCount(80, $options);
        $this->assertSame(1, $options[0]->bookCounts['medicine']);
        $this->assertSame(3, $options[0]->knowledgeCounts['medicine']);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(1, $state->players[0]->resources->books->unassigned);
        $this->assertSame(0, $state->players[0]->knowledge->medicine);
        $this->assertSame(1, $simulation->state->players[0]->resources->books->medicine);
        $this->assertSame(3, $simulation->state->players[0]->knowledge->medicine);
        $this->assertNotNull($simulation->state->turnStartSnapshot);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(10, $simulation->nextActiveUserId);
    }
}
