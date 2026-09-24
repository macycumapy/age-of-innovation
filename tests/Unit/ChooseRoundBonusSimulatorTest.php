<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Domain\Game\Services\ChooseRoundBonusOptionFinder;
use App\Domain\Game\Services\GameActionSimulator;
use Tests\TestCase;

class ChooseRoundBonusSimulatorTest extends TestCase
{
    public function test_it_simulates_round_bonus_choice_and_hands_turn_to_the_next_player(): void
    {
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'round-bonus-simulation');
        $setupPool->availableRoundBonuses = [
            new RoundBonusOfferData(RoundBonus::RiverWorkshop, 2),
            new RoundBonusOfferData(RoundBonus::BuildGuild, 1),
        ];
        $state = new GameStateData(
            turnOrder: [1, 2],
            passedPlayerIds: [1],
            players: [
                $this->player(1, 10, PlayerColor::Green, RoundBonus::Coins),
                $this->player(2, 20, PlayerColor::Blue, RoundBonus::PowerCoins),
            ],
            round: new RoundStateData(phase: GamePhase::Actions, passOrder: [1]),
            setupPool: $setupPool,
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseRoundBonus,
                1,
                [RoundBonus::RiverWorkshop->value, RoundBonus::BuildGuild->value],
            ),
        );
        $options = app(ChooseRoundBonusOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertCount(2, $options);
        $this->assertSame(RoundBonus::RiverWorkshop, $options[0]->roundBonus);
        $this->assertSame(2, $options[0]->coins);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(RoundBonus::Coins, $state->players[0]->roundBonus);
        $this->assertSame(0, $state->players[0]->resources->coins);
        $this->assertNotNull($state->pendingInteraction);
        $this->assertSame(RoundBonus::RiverWorkshop, $simulation->state->players[0]->roundBonus);
        $this->assertSame(2, $simulation->state->players[0]->resources->coins);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(RoundBonus::BuildGuild, $simulation->state->setupPool->availableRoundBonuses[0]->roundBonus);
        $this->assertSame(RoundBonus::Coins, $simulation->state->setupPool->availableRoundBonuses[1]->roundBonus);
        $this->assertSame(2, $simulation->nextActivePlayerId);
    }

    private function player(int $playerId, int $userId, PlayerColor $color, RoundBonus $roundBonus): GamePlayerStateData
    {
        return new GamePlayerStateData(
            playerId: $playerId,
            userId: $userId,
            color: $color,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: $roundBonus,
        );
    }
}
