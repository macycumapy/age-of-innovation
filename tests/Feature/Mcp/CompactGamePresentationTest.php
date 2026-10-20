<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\GameEngine\Board\Data\BridgeStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Data\BuildWorkshopOptionData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Board\Factories\BoardStateFactory;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Domain\GameSession\Data\GameActionOptionsData;
use App\Domain\GameSession\Data\GameStateViewData;
use App\Domain\GameSession\Data\StagedGameActionData;
use App\Mcp\Presentation\GameActionIdentifier;
use App\Mcp\Presentation\GameActionPresenter;
use App\Mcp\Presentation\GameBoardPresenter;
use App\Mcp\Presentation\GameStatePresenter;
use DomainException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class CompactGamePresentationTest extends TestCase
{
    #[DataProvider('mapVariants')]
    public function test_compact_board_is_lossless_and_smaller_without_mutating_domain_state(MapVariant $variant): void
    {
        $board = app(BoardStateFactory::class)->create($variant);
        $board->hexes[0]->building = new BuildingStateData(BuildingType::Workshop, 12, true, true);
        $board->hexes[0]->townId = 'town-1';
        $board->hexes[0]->townTileId = 'tile-1';
        $board->hexes[1]->building = new BuildingStateData(BuildingType::Guild, 13);
        $board->hexes[2]->townId = 'town-without-tile';
        $board->hexes[3]->terrain = TerrainType::Forest;
        $board->bridges = [new BridgeStateData($board->hexes[0]->id, $board->hexes[1]->id, 12)];
        $before = $board->toArray();

        $presenter = app(GameBoardPresenter::class);
        $compact = $presenter->present($board);
        $layout = $presenter->layout($board);
        $this->assertSame($layout['layout_id'], $compact['layout_id']);
        $geometry = [];
        foreach ($layout['hexes'] as $row) {
            $entry = array_combine($layout['hex_fields'], $row);
            $geometry[$entry['id']] = $entry;
        }
        $decodedHexes = [];
        foreach ($compact['hexes'] as $row) {
            $hex = array_combine($compact['hex_fields'], array_pad($row, count($compact['hex_fields']), null));
            if ($hex['building'] !== null) {
                $hex['building'] = array_combine($compact['building_fields'], $hex['building']);
            }
            $static = $geometry[$hex['id']];
            $decodedHexes[] = [
                'id' => $hex['id'], 'q' => $static['q'], 'r' => $static['r'],
                'initialTerrain' => $static['initialTerrain'], 'terrain' => $hex['terrain'],
                'adjacentHexIds' => $static['adjacentHexIds'], 'riverConnectedHexIds' => $static['riverConnectedHexIds'],
                'building' => $hex['building'], 'townId' => $hex['townId'], 'townTileId' => $hex['townTileId'],
            ];
        }
        $decodedBridges = array_map(fn (array $row): array => array_combine($compact['bridge_fields'], $row), $compact['bridges']);

        $expectedHexes = $before['hexes'];
        foreach ($expectedHexes as &$hex) {
            sort($hex['adjacentHexIds'], SORT_STRING);
            sort($hex['riverConnectedHexIds'], SORT_STRING);
        }
        unset($hex);
        $this->assertSame($expectedHexes, $decodedHexes);
        $this->assertSame($before['bridges'], $decodedBridges);
        $this->assertSame($before['variant'], $layout['variant']);
        $this->assertEqualsCanonicalizing($before['riverBankHexIds'], $layout['riverBankHexIds']);
        $this->assertEqualsCanonicalizing($before['edgeHexIds'], $layout['edgeHexIds']);
        $this->assertSame($before, $board->toArray());
        $this->assertLessThan(strlen(json_encode($before, JSON_THROW_ON_ERROR)) / 2, strlen(json_encode($compact, JSON_THROW_ON_ERROR)));
        $this->assertLessThan(
            strlen(json_encode($before, JSON_THROW_ON_ERROR)) / 2,
            strlen(json_encode($compact, JSON_THROW_ON_ERROR)) + strlen(json_encode($layout, JSON_THROW_ON_ERROR))
        );
    }

    public function test_empty_board_is_a_valid_self_describing_snapshot(): void
    {
        $payload = app(GameStatePresenter::class)->present(new GameStateViewData(1, 2, 3, 4, null, new GameStateData(), null));
        $this->assertSame('compact-v2', $payload['format']);
        $this->assertSame([], $payload['state']['board']['hexes']);
        $this->assertSame([], $payload['state']['board']['bridges']);
        $this->assertCount(5, $payload['state']['board']['hex_fields']);
        $this->assertNotEmpty($payload['state']['board']['layout_id']);
    }

    #[DataProvider('phases')]
    public function test_only_obsolete_setup_fields_are_omitted_after_setup(GamePhase $phase): void
    {
        $pool = app(GameSetupPoolFactory::class)->createFromSeed(2, 'compact-state');
        $state = new GameStateData(setupPool: $pool, round: new RoundStateData(phase: $phase));
        $before = $state->toArray();
        $payload = app(GameStatePresenter::class)->present(new GameStateViewData(1, 2, 3, 4, 3, $state, null));

        if ($phase === GamePhase::Setup) {
            $this->assertSame($before['setupPool'], $payload['state']['setupPool']);
            $this->assertArrayHasKey('planningSelections', $payload['state']);
        } else {
            $this->assertArrayNotHasKey('planningBundles', $payload['state']['setupPool']);
            $this->assertArrayNotHasKey('firstPlayerIndex', $payload['state']['setupPool']);
            $this->assertArrayNotHasKey('planningSelections', $payload['state']);
            $this->assertArrayNotHasKey('pendingStartingBuildingHexId', $payload['state']);
            $expectedPool = $before['setupPool'];
            unset($expectedPool['planningBundles'], $expectedPool['firstPlayerIndex']);
            $this->assertSame($expectedPool, $payload['state']['setupPool']);
        }
        $this->assertSame($before, $state->toArray());
    }

    public function test_short_action_identifiers_preserve_parameters_deduplicate_and_match_staged_selection(): void
    {
        $first = new BuildWorkshopOptionData('8:0');
        $second = new BuildWorkshopOptionData('8:1');
        $options = new GameActionOptionsData(138, [$first, $first, $second], true);
        $presenter = app(GameActionPresenter::class);
        $identifier = app(GameActionIdentifier::class);
        $payload = $presenter->legalActions($options);

        $this->assertCount(3, $payload['actions']);
        foreach ([$first, $second] as $index => $option) {
            $id = $payload['actions'][$index]['action_id'];
            $this->assertLessThan(25, strlen($id));
            $this->assertStringStartsWith('v138:', $id);
            $this->assertEquals($option, $identifier->resolve($id, $options));
            $this->assertSame($option->toArray(), $payload['actions'][$index]['parameters']);
            $this->assertSame($id, $presenter->staged(new StagedGameActionData(138, $option))['action_id']);
        }
        $this->assertNotSame($payload['actions'][0]['action_id'], $payload['actions'][1]['action_id']);
        $this->assertNull($identifier->resolve('finish_turn', $options));
    }

    public function test_action_identifier_from_another_version_is_rejected_even_if_option_remains_legal(): void
    {
        $identifier = app(GameActionIdentifier::class);
        $option = new BuildWorkshopOptionData('8:0');
        $id = $identifier->forOption($option, 138);
        $this->expectException(DomainException::class);
        $identifier->resolve($id, new GameActionOptionsData(139, [$option], false));
    }

    public function test_arbitrary_action_identifier_is_rejected(): void
    {
        $this->expectException(DomainException::class);
        app(GameActionIdentifier::class)->resolve('v138:invalid', new GameActionOptionsData(138, [new BuildWorkshopOptionData('8:0')], false));
    }

    /** @return list<array{MapVariant}> */
    public static function mapVariants(): array
    {
        return array_map(fn (MapVariant $variant): array => [$variant], MapVariant::cases());
    }

    /** @return list<array{GamePhase}> */
    public static function phases(): array
    {
        return array_map(fn (GamePhase $phase): array => [$phase], GamePhase::cases());
    }
}
