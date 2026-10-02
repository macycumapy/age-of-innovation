<?php

declare(strict_types=1);

namespace Tests\Unit\GameEngine\Interactions;

use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\Interactions\Services\GameActionOptionFinder;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Data\ChooseTownOptionData;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class GameActionOptionFinderTest extends TestCase
{
    public function test_it_aggregates_normal_action_and_resource_options(): void
    {
        $state = $this->state();

        $options = app(GameActionOptionFinder::class)->execute($state, 1);
        $types = array_map(static fn ($option): GameActionOptionType => $option->type(), $options);

        $this->assertContains(GameActionOptionType::Pass, $types);
        $this->assertContains(GameActionOptionType::ExchangeResources, $types);
        $this->assertNotContains(GameActionOptionType::ResolvePowerOffer, $types);
    }

    public function test_it_returns_pending_power_offer_and_free_resource_options_only(): void
    {
        $state = $this->state();
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::PowerOffer,
            1,
            context: ['buildingPlayerId' => 1, 'builtHexId' => '0:0', 'powerAmount' => 1],
        );

        $options = app(GameActionOptionFinder::class)->execute($state, 1);
        $types = array_map(static fn ($option): GameActionOptionType => $option->type(), $options);

        $this->assertSame(2, count(array_filter(
            $types,
            static fn (GameActionOptionType $type): bool => $type === GameActionOptionType::ResolvePowerOffer,
        )));
        $this->assertContains(GameActionOptionType::ExchangeResources, $types);
        $this->assertNotContains(GameActionOptionType::Pass, $types);
    }

    public function test_it_delegates_town_choices_to_the_typed_finder(): void
    {
        $state = $this->state();
        $state->availableTownTileIds = [TownTile::Coins->value, TownTile::Books->value];
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChooseTown,
            1,
            [TownTile::Coins->value, TownTile::Books->value],
            ['townHexIds' => ['0:0'], 'builtHexId' => '0:0'],
        );

        $options = app(GameActionOptionFinder::class)->execute($state, 1);
        $townOptions = array_values(array_filter(
            $options,
            static fn ($option): bool => $option instanceof ChooseTownOptionData,
        ));

        $this->assertCount(2, $townOptions);
        $this->assertSame(TownTile::Coins, $townOptions[0]->townTile);
        $this->assertSame(TownTile::Books, $townOptions[1]->townTile);
    }

    public function test_it_returns_no_options_for_an_unknown_player(): void
    {
        $this->assertSame([], app(GameActionOptionFinder::class)->execute($this->state(), 999));
    }

    public function test_it_returns_no_options_during_another_players_pending_interaction(): void
    {
        $state = $this->state();
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::PowerOffer,
            2,
            context: ['buildingPlayerId' => 1, 'builtHexId' => '0:0', 'powerAmount' => 1],
        );

        $this->assertSame([], app(GameActionOptionFinder::class)->execute($state, 1));
    }

    private function state(): GameStateData
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
                    power: new PowerBowlsStateData(bowlThree: 1),
                ),
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
    }
}
