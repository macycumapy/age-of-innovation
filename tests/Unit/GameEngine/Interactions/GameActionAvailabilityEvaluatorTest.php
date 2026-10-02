<?php

declare(strict_types=1);

namespace Tests\Unit\GameEngine\Interactions;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Enums\GameActionAvailabilityReason;
use App\Domain\GameEngine\Interactions\Services\GameActionAvailabilityEvaluator;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class GameActionAvailabilityEvaluatorTest extends TestCase
{
    public function test_it_uses_actual_upgrade_costs_instead_of_assuming_no_target(): void
    {
        $state = $this->state(new PlayerResourcesData(coins: 50, tools: 1));
        $state->board->hexes = [new BoardHexStateData(
            id: '0:0',
            q: 0,
            r: 0,
            initialTerrain: TerrainType::Forest,
            terrain: TerrainType::Forest,
            building: new BuildingStateData(BuildingType::Workshop, 1),
        )];

        $availability = app(GameActionAvailabilityEvaluator::class)->execute($state, $state->players[0]);
        $upgrade = collect($availability)->firstWhere('type', GameActionOptionType::UpgradeBuilding);

        $this->assertNotNull($upgrade);
        $this->assertSame(0, $upgrade->availableOptionCount);
        $this->assertSame([GameActionAvailabilityReason::InsufficientTools], $upgrade->unavailableReasons);
    }

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
        $this->assertContains(
            GameActionAvailabilityReason::InsufficientPower,
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
