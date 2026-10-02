<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\PassValueEvaluator;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
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
