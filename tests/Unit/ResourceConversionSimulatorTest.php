<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\ResourceExchangeOptionData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Data\SacrificePowerOptionData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\KnowledgeDiscipline;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\ResourceExchange;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionSimulator;
use App\Domain\Game\Services\ResourceConversionOptionFinder;
use InvalidArgumentException;
use Tests\TestCase;

class ResourceConversionSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_an_atomic_resource_exchange(): void
    {
        $state = $this->state();
        $options = app(ResourceConversionOptionFinder::class)->execute($state->players[0]);
        $lawOption = collect($options)->first(
            static fn ($option): bool => $option instanceof ResourceExchangeOptionData
                && $option->exchange === ResourceExchange::PowerToBook
                && $option->discipline === KnowledgeDiscipline::Law,
        );

        $this->assertInstanceOf(ResourceExchangeOptionData::class, $lawOption);
        $this->assertSame(GameActionOptionType::ExchangeResources, $lawOption->type());

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $lawOption);

        $this->assertSame(5, $state->players[0]->resources->power->bowlThree);
        $this->assertSame(0, $state->players[0]->resources->books->law);
        $this->assertSame(0, $simulation->state->players[0]->resources->power->bowlThree);
        $this->assertSame(5, $simulation->state->players[0]->resources->power->bowlOne);
        $this->assertSame(1, $simulation->state->players[0]->resources->books->law);
        $this->assertFalse($simulation->state->round->hasTakenMainAction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
    }

    public function test_it_enumerates_and_simulates_one_power_sacrifice(): void
    {
        $state = $this->state();
        $option = collect(app(ResourceConversionOptionFinder::class)->execute($state->players[0]))
            ->first(static fn ($option): bool => $option instanceof SacrificePowerOptionData);

        $this->assertInstanceOf(SacrificePowerOptionData::class, $option);
        $this->assertSame(GameActionOptionType::SacrificePower, $option->type());

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);

        $this->assertSame(4, $state->players[0]->resources->power->bowlTwo);
        $this->assertSame(2, $simulation->state->players[0]->resources->power->bowlTwo);
        $this->assertSame(6, $simulation->state->players[0]->resources->power->bowlThree);
        $this->assertFalse($simulation->state->round->hasTakenMainAction);
    }

    public function test_it_accepts_an_equivalent_exchange_created_independently(): void
    {
        $state = $this->state();
        $option = new ResourceExchangeOptionData(ResourceExchange::PowerToCoin);

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);

        $this->assertSame(1, $simulation->state->players[0]->resources->coins);
        $this->assertSame(4, $simulation->state->players[0]->resources->power->bowlThree);
        $this->assertSame(0, $state->players[0]->resources->coins);
    }

    public function test_it_rejects_a_book_exchange_for_an_unavailable_discipline(): void
    {
        $state = $this->state();
        $state->players[0]->resources->books->law = 1;

        $this->expectException(InvalidArgumentException::class);
        app(GameActionSimulator::class)->execute($state, 1, new ResourceExchangeOptionData(
            ResourceExchange::BookToCoin,
            KnowledgeDiscipline::Banking,
        ));
    }

    public function test_it_rejects_a_power_to_book_exchange_without_a_discipline(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(GameActionSimulator::class)->execute($this->state(), 1, new ResourceExchangeOptionData(ResourceExchange::PowerToBook));
    }

    public function test_it_rejects_an_unexpected_discipline_on_a_coin_exchange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(GameActionSimulator::class)->execute($this->state(), 1, new ResourceExchangeOptionData(
            ResourceExchange::PowerToCoin,
            KnowledgeDiscipline::Law,
        ));
    }

    public function test_it_rejects_a_sacrifice_amount_not_offered_by_the_finder(): void
    {
        $this->expectException(InvalidArgumentException::class);
        app(GameActionSimulator::class)->execute($this->state(), 1, new SacrificePowerOptionData(amount: 2));
    }

    private function state(): GameStateData
    {
        return new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Philosophers,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(
                    power: new PowerBowlsStateData(bowlTwo: 4, bowlThree: 5),
                ),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
    }
}
