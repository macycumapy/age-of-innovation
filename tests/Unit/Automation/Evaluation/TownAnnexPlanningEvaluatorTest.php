<?php

declare(strict_types=1);

namespace Tests\Unit\Automation\Evaluation;

use App\Domain\Automation\Evaluation\Services\BoardPositionProgressEvaluator;
use App\Domain\Automation\Evaluation\Services\TownAnnexPlanningEvaluator;
use App\Domain\Automation\Services\GameActionRanker;
use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Enums\GameActionOptionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use Tests\TestCase;

class TownAnnexPlanningEvaluatorTest extends TestCase
{
    public function test_an_annex_on_an_isolated_workshop_has_no_town_progress_value(): void
    {
        $before = $this->state();
        $before->players[0]->availableAnnexes = 1;
        $before->board->hexes = $this->group('alone', [BuildingType::Workshop]);
        $after = $before->deepCopy();
        $after->players[0]->availableAnnexes = 0;
        $after->board->hexes[0]->building->hasAnnex = true;

        $planning = app(TownAnnexPlanningEvaluator::class)->execute($after, $after->players[0], [$after->board->hexes], [0]);

        $this->assertSame(0, $planning->projectedTowns);
        $this->assertSame([], $planning->usefulAnnexHexIds);
        $this->assertSame(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_one_available_annex_cannot_be_used_for_two_groups(): void
    {
        $state = $this->state();
        $state->players[0]->availableAnnexes = 1;
        $first = $this->group('first', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        $second = $this->group('second', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        $state->board->hexes = [...$first, ...$second];
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$first, $second], [216, 216])->projectedTowns);
        $state->players[0]->availableAnnexes = 2;
        $this->assertSame(2, $evaluator->execute($state, $state->players[0], [$first, $second], [216, 216])->projectedTowns);
    }

    public function test_saving_the_second_annex_keeps_two_towns_reachable_after_upgrades(): void
    {
        $before = $this->state();
        $before->round->number = 3;
        $before->players[0]->resources = new PlayerResourcesData(tools: 11, coins: 26);
        $before->players[0]->availableAnnexes = 1;
        $first = $this->group('first', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $first[2]->building->hasAnnex = true;
        $second = $this->group('second', [BuildingType::Workshop, BuildingType::Workshop, BuildingType::Workshop]);
        $before->board->hexes = [...$first, ...$second];
        $evaluator = app(TownAnnexPlanningEvaluator::class);
        $plan = $evaluator->execute($before, $before->players[0], [$first, $second], [288, 48]);

        $this->assertSame(2, $plan->projectedTowns);
        $after = $before->deepCopy();
        $after->players[0]->availableAnnexes = 0;
        $after->board->hexes[0]->building->hasAnnex = true;
        $placed = $evaluator->execute($after, $after->players[0], [array_slice($after->board->hexes, 0, 3), array_slice($after->board->hexes, 3)], [392, 48]);
        $this->assertSame(1, $placed->projectedTowns);
        $this->assertLessThan(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
        $this->assertSame(1, $before->players[0]->availableAnnexes);
        $this->assertFalse($before->board->hexes[0]->building->hasAnnex);
    }

    public function test_the_same_university_supply_and_resources_are_not_forecast_twice(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 10, coins: 16);
        $first = $this->group('first', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $second = $this->group('second', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $first[2]->building->hasAnnex = true;
        $second[2]->building->hasAnnex = true;
        $first[2]->building->isNeutral = true;
        $second[2]->building->isNeutral = true;
        $state->board->hexes = [...$first, ...$second, ...$this->group('palace', [BuildingType::Palace])];

        $this->assertSame(1, app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [$first, $second], [288, 288])->projectedTowns);
    }

    public function test_an_unaffordable_upgrade_does_not_give_an_annex_future_town_value(): void
    {
        $state = $this->state();
        $group = $this->group('group', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $group[2]->building->hasAnnex = true;
        $state->board->hexes = $group;
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $this->assertSame(0, $evaluator->execute($state, $state->players[0], [$group], [288])->projectedTowns);
        $state->players[0]->resources = new PlayerResourcesData(tools: 5, coins: 8);
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$group], [288])->projectedTowns);
    }

    public function test_the_ranker_allocates_the_second_annex_to_the_other_group(): void
    {
        $state = $this->state();
        $state->round->number = 3;
        $state->turnOrder = [1];
        $state->availableTownTileIds = [TownTile::Coins->value, TownTile::Tools->value];
        $state->players[0]->resources = new PlayerResourcesData(tools: 11, coins: 26);
        $state->players[0]->availableAnnexes = 1;
        $first = $this->group('first', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $first[2]->building->hasAnnex = true;
        $second = $this->group('second', [BuildingType::Workshop, BuildingType::Workshop, BuildingType::Workshop]);
        $state->board->hexes = [...$first, ...$second];

        $ranked = app(GameActionRanker::class)->execute($state, 1, depth: 1);

        $this->assertSame(GameActionOptionType::PlaceAnnex, $ranked[0]->option->type());
        $this->assertStringStartsWith('second-', $ranked[0]->option->hexId);
        $after = $ranked[0]->simulation->state;
        $planning = app(TownAnnexPlanningEvaluator::class)->execute(
            $after,
            $after->players[0],
            [array_slice($after->board->hexes, 0, 3), array_slice($after->board->hexes, 3)],
            [288, 128],
        );
        $this->assertSame(2, $planning->projectedTowns);
        $this->assertSame(1, $state->players[0]->availableAnnexes);
    }

    public function test_neutral_buildings_are_not_upgraded_in_the_forecast(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 30, coins: 60);
        $state->players[0]->availableAnnexes = 1;
        $group = $this->group('group', [BuildingType::Workshop, BuildingType::Workshop, BuildingType::Workshop]);
        foreach ($group as $hex) {
            $hex->building->isNeutral = true;
        }
        $state->board->hexes = $group;

        $this->assertSame(0, app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [$group], [48])->projectedTowns);
    }

    public function test_the_second_group_can_be_completed_by_one_adjacent_workshop_and_upgrades(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 12, coins: 28);
        $state->players[0]->availableAnnexes = 1;
        $first = $this->group('first', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $first[2]->building->hasAnnex = true;
        $second = $this->group('second', [BuildingType::Workshop, BuildingType::Workshop]);
        $second[1]->adjacentHexIds[] = 'target';
        $target = new BoardHexStateData('target', 3, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['second-1']);
        $state->board->hexes = [...$first, ...$second, $target];
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $this->assertSame(2, $evaluator->execute($state, $state->players[0], [$first, $second], [288, 16])->projectedTowns);
        $target->building = new BuildingStateData(BuildingType::Workshop, 2);
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$first, $second], [288, 16])->projectedTowns);
    }

    public function test_forecast_workshops_include_terraforming_cost_and_supply_limits(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 7, coins: 20);
        $state->players[0]->availableAnnexes = 1;
        $group = $this->group('group', [BuildingType::Workshop, BuildingType::Workshop]);
        $group[1]->adjacentHexIds[] = 'target';
        $target = new BoardHexStateData('target', 2, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['group-1']);
        $state->board->hexes = [...$group, $target];
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$group], [16])->projectedTowns);
        $target->terrain = TerrainType::Mountain;
        $this->assertSame(0, $evaluator->execute($state, $state->players[0], [$group], [16])->projectedTowns);
        $target->terrain = TerrainType::Forest;
        for ($index = 0; $index < 7; $index++) {
            $state->board->hexes[] = $this->group('other-'.$index, [BuildingType::Workshop])[0];
        }
        $this->assertSame(0, $evaluator->execute($state, $state->players[0], [$group], [16])->projectedTowns);
    }

    public function test_forecast_expansion_cannot_join_an_existing_town(): void
    {
        $state = $this->state();
        $state->players[0]->resources = new PlayerResourcesData(tools: 20, coins: 40);
        $state->players[0]->availableAnnexes = 1;
        $group = $this->group('group', [BuildingType::Workshop, BuildingType::Workshop]);
        $group[1]->adjacentHexIds[] = 'target';
        $target = new BoardHexStateData('target', 2, 0, TerrainType::Forest, TerrainType::Forest, adjacentHexIds: ['group-1', 'old-0']);
        $town = $this->group('old', [BuildingType::Palace]);
        $town[0]->townId = 'old-town';
        $state->board->hexes = [...$group, $target, ...$town];

        $this->assertSame(1, app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [$group], [16])->projectedTowns);
    }

    public function test_upgrade_costs_share_one_resource_budget_across_groups(): void
    {
        $state = $this->state();
        $first = $this->group('first', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $second = $this->group('second', [BuildingType::School, BuildingType::Guild, BuildingType::Workshop]);
        $first[2]->building->hasAnnex = true;
        $first[2]->building->isNeutral = true;
        $second[2]->building->hasAnnex = true;
        $state->board->hexes = [...$first, ...$second];
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $state->players[0]->resources = new PlayerResourcesData(tools: 5, coins: 8);
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$first, $second], [288, 288])->projectedTowns);
        $state->players[0]->resources = new PlayerResourcesData(tools: 7, coins: 14);
        $this->assertSame(2, $evaluator->execute($state, $state->players[0], [$first, $second], [288, 288])->projectedTowns);
    }

    public function test_town_forecasts_respect_the_remaining_town_tile_supply(): void
    {
        $state = $this->state();
        $state->players[0]->availableAnnexes = 2;
        $first = $this->group('first', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        $second = $this->group('second', [BuildingType::Guild, BuildingType::Guild, BuildingType::Guild]);
        $state->board->hexes = [...$first, ...$second];
        $evaluator = app(TownAnnexPlanningEvaluator::class);

        $state->availableTownTileIds = [TownTile::Coins->value];
        $this->assertSame(1, $evaluator->execute($state, $state->players[0], [$first, $second], [216, 216])->projectedTowns);
        $state->availableTownTileIds = [];
        $this->assertSame(0, $evaluator->execute($state, $state->players[0], [$first, $second], [216, 216])->projectedTowns);
    }

    public function test_a_six_power_town_preserves_its_forecast_value_when_founded(): void
    {
        $before = $this->state();
        $before->players[0]->palaceId = PalaceAbility::Palace08->value;
        $group = $this->group('group', [BuildingType::Guild, BuildingType::Guild]);
        foreach ($group as $hex) {
            $hex->building->hasAnnex = true;
        }
        $before->board->hexes = $group;
        $after = $before->deepCopy();
        array_pop($after->availableTownTileIds);
        foreach ($after->board->hexes as $hex) {
            $hex->townId = 'town';
        }

        $this->assertSame(0, app(BoardPositionProgressEvaluator::class)->execute($before, $after, 1));
    }

    public function test_allocation_prefers_two_one_annex_towns_over_one_two_annex_town(): void
    {
        $state = $this->state();
        $state->players[0]->palaceId = PalaceAbility::Palace08->value;
        $state->players[0]->availableAnnexes = 2;
        $state->availableTownTileIds = [TownTile::Coins->value, TownTile::Tools->value, TownTile::Books->value];
        $costly = $this->group('costly', [BuildingType::Guild, BuildingType::School]);
        $first = $this->group('first', [BuildingType::Palace, BuildingType::Tower, BuildingType::Workshop]);
        $second = $this->group('second', [BuildingType::Guild, BuildingType::School, BuildingType::Tower]);
        $first[1]->building->isNeutral = true;
        $second[2]->building->isNeutral = true;
        $state->board->hexes = [...$costly, ...$first, ...$second];

        $planning = app(TownAnnexPlanningEvaluator::class)->execute($state, $state->players[0], [$costly, $first, $second], [64, 216, 216]);

        $this->assertSame(2, $planning->projectedTowns);
        $this->assertSame(672, $planning->score);
    }

    private function state(): GameStateData
    {
        return new GameStateData(
            players: [new GamePlayerStateData(
                playerId: 1,
                userId: null,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(),
            )],
            round: new RoundStateData(number: 6, phase: GamePhase::Actions),
            availableTownTileIds: [TownTile::Coins->value, TownTile::Tools->value],
        );
    }

    /**
     * @param list<BuildingType> $types
     * @return list<BoardHexStateData>
     */
    private function group(string $prefix, array $types): array
    {
        $group = [];
        foreach ($types as $index => $type) {
            $neighbors = [];
            if ($index > 0) {
                $neighbors[] = $prefix.'-'.($index - 1);
            }
            if ($index < count($types) - 1) {
                $neighbors[] = $prefix.'-'.($index + 1);
            }
            $group[] = new BoardHexStateData(
                id: $prefix.'-'.$index,
                q: $index,
                r: 0,
                terrain: TerrainType::Forest,
                initialTerrain: TerrainType::Forest,
                building: new BuildingStateData($type, 1),
                adjacentHexIds: $neighbors,
            );
        }

        return $group;
    }
}
