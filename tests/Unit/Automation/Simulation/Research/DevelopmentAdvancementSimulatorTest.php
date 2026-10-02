<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\Research;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Services\DevelopmentAdvancementOptionFinder;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class DevelopmentAdvancementSimulatorTest extends TestCase
{
    public function test_it_generates_and_simulates_shipping_advancement_without_mutating_the_source(): void
    {
        $state = $this->state();
        $options = app(DevelopmentAdvancementOptionFinder::class)->execute($state, $state->players[0]);
        $option = collect($options)->firstWhere('action', GameActionType::AdvanceShipping);

        $this->assertNotNull($option);
        $this->assertSame(1, $option->targetLevel);
        $this->assertSame(4, $option->coins);
        $this->assertSame(1, $option->scholars);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);

        $this->assertSame(0, $state->players[0]->shippingLevel);
        $this->assertSame(10, $state->players[0]->resources->coins);
        $this->assertFalse($state->round->hasTakenMainAction);
        $this->assertSame(1, $simulation->state->players[0]->shippingLevel);
        $this->assertSame(6, $simulation->state->players[0]->resources->coins);
        $this->assertSame(1, $simulation->state->players[0]->resources->scholars);
        $this->assertSame(22, $simulation->state->players[0]->victoryPoints);
        $this->assertTrue($simulation->state->round->hasTakenMainAction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    public function test_it_simulates_terraforming_advancement_and_its_book_choice(): void
    {
        $state = $this->state();
        $options = app(DevelopmentAdvancementOptionFinder::class)->execute($state, $state->players[0]);
        $option = collect($options)->firstWhere('action', GameActionType::AdvanceTerraforming);

        $this->assertNotNull($option);
        $this->assertSame(1, $option->tools);
        $this->assertSame(5, $option->coins);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);

        $this->assertSame(0, $state->players[0]->terraformingLevel);
        $this->assertNull($state->pendingInteraction);
        $this->assertSame(1, $simulation->state->players[0]->terraformingLevel);
        $this->assertSame(1, $simulation->state->players[0]->resources->tools);
        $this->assertSame(5, $simulation->state->players[0]->resources->coins);
        $this->assertSame(2, $simulation->state->players[0]->resources->books->unassigned);
        $this->assertSame(PendingInteractionType::ChooseTerraformingBooks, $simulation->state->pendingInteraction->type);
        $this->assertSame(2, $simulation->state->pendingInteraction->context['bookCount']);
    }

    private function state(): GameStateData
    {
        return new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(tools: 2, coins: 10, scholars: 2),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
    }
}
