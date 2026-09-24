<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\PowerOfferOptionFinder;
use Tests\TestCase;

class PowerOfferSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_accepting_a_power_offer(): void
    {
        $state = $this->state();
        $options = app(PowerOfferOptionFinder::class)->execute($state, 2);

        $this->assertCount(2, $options);
        $this->assertTrue($options[0]->accept);
        $this->assertFalse($options[1]->accept);
        $this->assertSame(GameActionOptionType::ResolvePowerOffer, $options[0]->type());

        $simulation = app(GameActionSimulator::class)->execute($state, 2, $options[0]);

        $this->assertSame(2, $state->players[1]->resources->power->bowlOne);
        $this->assertSame(20, $state->players[1]->victoryPoints);
        $this->assertNotNull($state->pendingInteraction);
        $this->assertSame(0, $simulation->state->players[1]->resources->power->bowlOne);
        $this->assertSame(2, $simulation->state->players[1]->resources->power->bowlTwo);
        $this->assertSame(19, $simulation->state->players[1]->victoryPoints);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    public function test_it_simulates_declining_a_power_offer(): void
    {
        $state = $this->state();
        $option = app(PowerOfferOptionFinder::class)->execute($state, 2)[1];

        $simulation = app(GameActionSimulator::class)->execute($state, 2, $option);

        $this->assertSame(2, $simulation->state->players[1]->resources->power->bowlOne);
        $this->assertSame(0, $simulation->state->players[1]->resources->power->bowlTwo);
        $this->assertSame(20, $simulation->state->players[1]->victoryPoints);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    private function state(): GameStateData
    {
        return new GameStateData(
            turnOrder: [1, 2],
            players: [
                new GamePlayerStateData(
                    playerId: 1,
                    userId: 10,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                ),
                new GamePlayerStateData(
                    playerId: 2,
                    userId: 20,
                    color: PlayerColor::Blue,
                    faction: Faction::Philosophers,
                    homeland: TerrainType::Swamp,
                    roundBonus: RoundBonus::PowerCoins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlOne: 2),
                    ),
                ),
            ],
            round: new RoundStateData(phase: GamePhase::Actions),
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::PowerOffer,
                2,
                context: [
                    'buildingPlayerId' => 1,
                    'builtHexId' => '0:0',
                    'powerAmount' => 2,
                    'remainingOffers' => [],
                ],
            ),
        );
    }
}
