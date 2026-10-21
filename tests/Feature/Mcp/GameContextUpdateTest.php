<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\MapVariant;
use App\Domain\GameEngine\Board\Factories\BoardStateFactory;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Domain\GameSession\Data\GameActionOptionsData;
use App\Domain\GameSession\Data\GameStateViewData;
use App\Domain\GameSession\Data\GameTurnContextData;
use App\Mcp\Presentation\GameContextUpdatePresenter;
use App\Mcp\Presentation\GameStatePresenter;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameContextUpdateTest extends TestCase
{
    public function test_delta_reconstructs_nulls_lists_board_rows_and_removed_setup_fields(): void
    {
        $state = new GameStateData(board: app(BoardStateFactory::class)->create(MapVariant::ThreeToFivePlayers));
        $state->availableInventionIds = ['a', 'b'];
        $state->round->scoringTileId = 'old';
        $afterState = $state->deepCopy();
        $afterState->round->phase = GamePhase::Actions;
        $afterState->round->scoringTileId = null;
        $afterState->availableInventionIds = [];
        $afterState->availablePalaceIds = ['new'];
        $afterState->board->hexes[0]->building = new BuildingStateData(BuildingType::Workshop, 7);
        $afterState->board->hexes[1]->townTileId = 'new-town';
        $before = $this->stateView($state, 10);
        $after = new GameTurnContextData($this->stateView($afterState, 11), new GameActionOptionsData(11, [], true));
        $original = app(GameStatePresenter::class)->present($before);
        $update = app(GameContextUpdatePresenter::class)->present($before, $after);

        $this->assertSame('context-delta-v1', $update['format']);
        $this->assertSame(10, $update['base_version']);
        $this->assertSame(11, $update['version']);
        $restored = $original;
        foreach ($update['changes'] as $change) {
            $path = $change['path'];
            $key = array_pop($path);
            $target = &$restored;
            foreach ($path as $segment) {
                $target = &$target[$segment];
            }
            if ($change['op'] === 'remove') {
                unset($target[$key]);
            } else {
                $target[$key] = $change['value'];
            }
            unset($target);
        }
        $restored['actions'] = $update['actions'];
        $expected = app(GameStatePresenter::class)->turnContext($after);
        $this->assertSame($expected, $restored);
        $this->assertLessThan(strlen(json_encode($expected)), strlen(json_encode($update)));
        $this->assertSame($original, app(GameStatePresenter::class)->present($before));
        $this->assertContains('remove', array_column($update['changes'], 'op'));
        $this->assertSame('finish_turn', $update['actions'][0]['action_id']);
    }

    public function test_large_replacement_uses_a_full_snapshot_when_it_is_smaller(): void
    {
        $state = new GameStateData();
        foreach (get_object_vars($state->round) as $key => $value) {
            $state->round->{$key} = match (true) {
                is_int($value) => $value + 1,
                is_bool($value) => ! $value,
                is_array($value) => [],
                default => $value,
            };
        }
        $state->round->phase = GamePhase::Actions;
        $state->round->scoringTileId = 'old';
        $state->round->additionalScoringTileId = 'old';
        $state->round->turnStartVersion = 9;
        $state->round->usedSharedActionIds = ['old'];
        $state->round->usedBookActionIds = ['old'];
        $state->round->incomeOrder = [1];
        $state->round->passOrder = [1];
        $state->availablePalaceIds = ['old'];
        $state->availableInventionIds = ['old'];
        $state->availableTownTileIds = ['old'];
        $state->availableCompetencyIds = ['old'];
        $state->roundBonusIds = ['old'];
        $state->turnOrder = [1];
        $state->passedPlayerIds = [1];
        $after = new GameTurnContextData($this->stateView(new GameStateData(), 11), new GameActionOptionsData(11, [], false));

        $update = app(GameContextUpdatePresenter::class)->present($this->stateView($state, 10), $after);
        $this->assertSame(['format' => 'context-snapshot-v1', 'context' => app(GameStatePresenter::class)->turnContext($after)], $update);
    }

    #[DataProvider('invalidContexts')]
    public function test_mismatched_contexts_are_rejected(int $gameId, int $userId, int $playerId, int $version, int $actionsVersion): void
    {
        $state = new GameStateData();
        $after = new GameTurnContextData(new GameStateViewData($gameId, $userId, $playerId, $version, 7, $state, null), new GameActionOptionsData($actionsVersion, [], false));
        $this->expectException(LogicException::class);
        app(GameContextUpdatePresenter::class)->present($this->stateView($state, 10), $after);
    }

    /** @return list<array{int, int, int, int, int}> */
    public static function invalidContexts(): array
    {
        return [[2, 3, 7, 11, 11], [1, 4, 7, 11, 11], [1, 3, 8, 11, 11], [1, 3, 7, 10, 10], [1, 3, 7, 9, 9], [1, 3, 7, 11, 12]];
    }

    private function stateView(GameStateData $state, int $version): GameStateViewData
    {
        return new GameStateViewData(1, 3, 7, $version, 7, $state, null);
    }
}
