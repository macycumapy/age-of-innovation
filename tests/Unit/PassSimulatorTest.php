<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\RoundBonusOfferData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\PassOptionFinder;
use Tests\TestCase;

class PassSimulatorTest extends TestCase
{
    public function test_it_simulates_pass_until_round_bonus_choice_without_mutating_the_source(): void
    {
        $setupPool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'pass-simulation');
        $setupPool->availableRoundBonuses = [
            new RoundBonusOfferData(RoundBonus::RiverWorkshop, 2),
            new RoundBonusOfferData(RoundBonus::BuildGuild, 1),
        ];
        $state = new GameStateData(
            turnOrder: [1, 2],
            players: [
                $this->player(1, 10, PlayerColor::Green, RoundBonus::Coins),
                $this->player(2, 20, PlayerColor::Blue, RoundBonus::PowerCoins),
            ],
            round: new RoundStateData(phase: GamePhase::Actions),
            setupPool: $setupPool,
        );
        $options = app(PassOptionFinder::class)->execute($state, $state->players[0]);

        $this->assertCount(1, $options);
        $this->assertSame([], $options[0]->knowledgeDisciplines);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame([], $state->passedPlayerIds);
        $this->assertNull($state->pendingInteraction);
        $this->assertSame([1], $simulation->state->passedPlayerIds);
        $this->assertSame([1], $simulation->state->round->passOrder);
        $this->assertSame(PendingInteractionType::ChooseRoundBonus, $simulation->state->pendingInteraction->type);
        $this->assertSame(
            [RoundBonus::RiverWorkshop->value, RoundBonus::BuildGuild->value],
            $simulation->state->pendingInteraction->optionIds,
        );
        $this->assertSame(10, $simulation->nextActiveUserId);
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
