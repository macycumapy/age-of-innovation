<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Contracts\GameActionOption;
use App\Domain\Game\Data\BookActionOptionData;
use App\Domain\Game\Data\BookPaymentData;
use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Enums\BookAction;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Factories\GameSetupPoolFactory;
use App\Domain\Game\Services\GameActionSimulator;
use DomainException;
use Tests\TestCase;

class GameActionSimulatorTest extends TestCase
{
    public function test_it_simulates_a_supported_action_through_the_common_contract(): void
    {
        $state = $this->state();
        $option = new BookActionOptionData(
            BookAction::GainCoins,
            new BookPaymentData(banking: 1, law: 1),
        );

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);

        $this->assertSame(6, $simulation->state->players[0]->resources->coins);
        $this->assertSame(10, $simulation->nextActiveUserId);
        $this->assertSame(0, $state->players[0]->resources->coins);
        $this->assertSame([], $state->round->usedBookActionIds);
    }

    public function test_it_rejects_an_unsupported_action_type(): void
    {
        $option = new class () implements GameActionOption {
            public function type(): GameActionOptionType
            {
                return GameActionOptionType::UsePalaceAction;
            }
        };

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('Симуляция действия use_palace_action ещё не поддерживается.');

        app(GameActionSimulator::class)->execute($this->state(), 1, $option);
    }

    private function state(): GameStateData
    {
        $setupPool = app(GameSetupPoolFactory::class)->create(2);
        $setupPool->bookActions = [BookAction::GainCoins];

        return new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(
                    books: new BookSupplyData(banking: 1, law: 1),
                ),
            )],
            setupPool: $setupPool,
        );
    }
}
