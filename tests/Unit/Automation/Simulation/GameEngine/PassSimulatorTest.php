<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Simulation\GameEngine;

use App\Domain\Automation\Services\GameActionSimulator;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Data\RoundBonusOfferData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Domain\GameEngine\Turns\Services\PassOptionFinder;
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
        $this->assertSame(1, $simulation->nextActivePlayerId);
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
