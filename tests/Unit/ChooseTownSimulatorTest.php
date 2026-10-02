<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Actions\ApplyChooseTownAction;
use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GameActionOptionType;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Enums\TownTile;
use App\Domain\Game\Services\ChooseTownOptionFinder;
use App\Domain\Game\Services\GameActionSimulator;
use Tests\TestCase;

class ChooseTownSimulatorTest extends TestCase
{
    public function test_it_enumerates_and_simulates_a_town_tile_without_mutating_the_source(): void
    {
        $state = $this->state();
        $options = app(ChooseTownOptionFinder::class)->execute($state, 1);

        $this->assertCount(2, $options);
        $this->assertSame(TownTile::Coins, $options[0]->townTile);
        $this->assertSame(GameActionOptionType::ChooseTown, $options[0]->type());

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $options[0]);

        $this->assertSame(0, $state->players[0]->resources->coins);
        $this->assertSame(20, $state->players[0]->victoryPoints);
        $this->assertNull($state->board->hexes[0]->townId);
        $this->assertSame(6, $simulation->state->players[0]->resources->coins);
        $this->assertSame(26, $simulation->state->players[0]->victoryPoints);
        $this->assertSame([TownTile::Coins->value], $simulation->state->players[0]->townTileIds);
        $this->assertNotNull($simulation->state->board->hexes[0]->townId);
        $this->assertSame(TownTile::Coins->value, $simulation->state->board->hexes[0]->townTileId);
        $this->assertSame([TownTile::Books->value], $simulation->state->availableTownTileIds);
        $this->assertNull($simulation->state->pendingInteraction);
        $this->assertSame(1, $simulation->nextActivePlayerId);
        $this->assertNull($simulation->state->townChoiceCheckpoint);
    }

    public function test_books_tile_creates_the_follow_up_distribution(): void
    {
        $state = $this->state();
        $option = app(ChooseTownOptionFinder::class)->execute($state, 1)[1];

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);

        $this->assertSame(2, $simulation->state->players[0]->resources->books->unassigned);
        $this->assertSame(PendingInteractionType::ChooseTownBooks, $simulation->state->pendingInteraction?->type);
        $this->assertSame(2, $simulation->state->pendingInteraction->context['bookCount']);
        $this->assertNull($simulation->state->townChoiceCheckpoint);
    }

    public function test_a_real_town_choice_preserves_the_complete_pre_choice_checkpoint(): void
    {
        $state = $this->state();
        $before = $state->toArray();

        app(ApplyChooseTownAction::class)->execute($state, $state->players[0], TownTile::Coins);

        $this->assertSame($before, $state->townChoiceCheckpoint);
        $this->assertSame(6, $state->players[0]->resources->coins);
        $this->assertSame([TownTile::Coins->value], $state->players[0]->townTileIds);
    }

    public function test_simulating_a_town_choice_does_not_replace_an_existing_checkpoint(): void
    {
        $state = $this->state();
        $state->townChoiceCheckpoint = ['version' => 11];
        $option = app(ChooseTownOptionFinder::class)->execute($state, 1)[0];

        $simulation = app(GameActionSimulator::class)->execute($state, 1, $option);

        $this->assertSame(['version' => 11], $state->townChoiceCheckpoint);
        $this->assertSame(['version' => 11], $simulation->state->townChoiceCheckpoint);
    }

    private function state(): GameStateData
    {
        return new GameStateData(
            board: new BoardStateData(hexes: [new BoardHexStateData(
                id: '0:0',
                q: 0,
                r: 0,
                initialTerrain: TerrainType::Forest,
                terrain: TerrainType::Forest,
                building: new BuildingStateData(BuildingType::Workshop, 1),
            )]),
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: 10,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
            availableTownTileIds: [TownTile::Coins->value, TownTile::Books->value],
            pendingInteraction: new PendingInteractionData(
                PendingInteractionType::ChooseTown,
                1,
                [TownTile::Coins->value, TownTile::Books->value],
                ['townHexIds' => ['0:0'], 'builtHexId' => '0:0'],
            ),
        );
    }
}
