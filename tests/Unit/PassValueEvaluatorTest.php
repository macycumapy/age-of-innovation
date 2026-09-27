<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BookSupplyData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\PassValueEvaluator;
use InvalidArgumentException;
use Tests\TestCase;

class PassValueEvaluatorTest extends TestCase
{
    public function test_it_penalizes_unspent_resources(): void
    {
        $this->assertSame(32, app(PassValueEvaluator::class)->execute($this->state(1), 1));
    }

    public function test_penalty_increases_towards_the_end_of_the_game(): void
    {
        $evaluator = app(PassValueEvaluator::class);

        $this->assertSame(
            $evaluator->execute($this->state(1), 1) * 3,
            $evaluator->execute($this->state(6), 1),
        );
    }

    public function test_it_rejects_an_unknown_player(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(PassValueEvaluator::class)->execute($this->state(1), 999);
    }

    private function state(int $round): GameStateData
    {
        return new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(
                    coins: 10,
                    tools: 2,
                    scholars: 1,
                    books: new BookSupplyData(banking: 1, law: 1),
                    power: new PowerBowlsStateData(bowlTwo: 3, bowlThree: 2),
                ),
                unassignedSpades: 1,
            )],
            round: new RoundStateData(number: $round),
        );
    }
}
