<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionAvailabilityReason;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\GameActionAvailabilityEvaluator;
use Tests\TestCase;

class GameActionAvailabilityEvaluatorTest extends TestCase
{
    public function test_it_reports_typed_reasons_for_unavailable_actions(): void
    {
        $state = $this->state();

        $availability = app(GameActionAvailabilityEvaluator::class)->execute($state, $state->players[0]);
        $byType = collect($availability)->keyBy(
            static fn ($item): string => $item->type->value,
        );

        $this->assertSame(
            [GameActionAvailabilityReason::InsufficientTools, GameActionAvailabilityReason::InsufficientCoins],
            $byType->get(GameActionOptionType::BuildWorkshop->value)?->unavailableReasons,
        );
        $this->assertSame(
            [GameActionAvailabilityReason::InsufficientScholars],
            $byType->get(GameActionOptionType::SendScholar->value)?->unavailableReasons,
        );
        $this->assertSame(
            [GameActionAvailabilityReason::InsufficientPower],
            $byType->get(GameActionOptionType::PowerAction->value)?->unavailableReasons,
        );
    }

    public function test_it_reports_the_actual_number_of_available_options(): void
    {
        $state = $this->state(new PlayerResourcesData(scholars: 1));

        $availability = app(GameActionAvailabilityEvaluator::class)->execute($state, $state->players[0]);
        $sendScholar = collect($availability)->firstWhere('type', GameActionOptionType::SendScholar);

        $this->assertNotNull($sendScholar);
        $this->assertSame(8, $sendScholar->availableOptionCount);
        $this->assertSame([], $sendScholar->unavailableReasons);
    }

    private function state(?PlayerResourcesData $resources = null): GameStateData
    {
        return new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: $resources ?? new PlayerResourcesData(),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
    }
}
