<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Domain\GameEngine\Board\Data\BoardHexStateData;
use App\Domain\GameEngine\Board\Data\BoardStateData;
use App\Domain\GameEngine\Board\Data\BuildingStateData;
use App\Domain\GameEngine\Board\Data\BuildWorkshopOptionData;
use App\Domain\GameEngine\Board\Enums\BuildingType;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Contracts\GameActionOption;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Setup\Actions\StartGameAction;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Domain\GameSession\Actions\ConfirmGameAction;
use App\Domain\GameSession\Actions\RollbackGameAction;
use App\Domain\GameSession\Actions\StageGameAction;
use App\Domain\GameSession\Data\ConfirmedGameActionData;
use App\Domain\GameSession\Data\GameActionOptionsData;
use App\Domain\GameSession\Data\GameStateViewData;
use App\Domain\GameSession\Data\StagedGameActionData;
use App\Domain\GameSession\Enums\GameActionContinuation;
use App\Domain\GameSession\Queries\GetGameStateQuery;
use App\Domain\GameSession\Queries\GetLegalGameActionsQuery;
use App\Domain\GameSession\Services\StagedGameActionStore;
use App\Events\GameChanged;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class GameActionStagingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.stores.array.serialize' => true, 'cache.serializable_classes' => false]);
        Cache::purge('array');
    }

    public function test_domain_staging_and_rollback_preserve_the_game_and_return_typed_values(): void
    {
        [$game, $user, $player] = $this->game();
        $before = $game->fresh()->getAttributes();
        $historyCount = $game->actions()->count();
        $options = app(GetLegalGameActionsQuery::class)->execute($user, $game->id);
        $this->assertInstanceOf(GameActionOptionsData::class, $options);
        $this->assertInstanceOf(GameActionOption::class, $options->options[0]);
        Event::fake([GameChanged::class]);

        $staged = app(StageGameAction::class)->execute($user, $game->id, $options->version, $options->options[0]);
        $view = app(GetGameStateQuery::class)->execute($user, $game->id);
        $this->assertInstanceOf(GameStateViewData::class, $view);
        $this->assertEquals($staged, $view->stagedAction);
        $this->assertEquals($options->options[0], $staged->option);
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertSame($historyCount, $game->actions()->count());

        $result = app(RollbackGameAction::class)->execute($user, $game->id, $options->version);
        $this->assertInstanceOf(Game::class, $result);
        $this->assertNull(app(StagedGameActionStore::class)->get($player));
        $this->assertSame($before, $game->fresh()->getAttributes());
        Event::assertNotDispatched(GameChanged::class);
    }

    public function test_confirmation_executes_the_typed_selection_without_a_transport_identifier(): void
    {
        [$game, $user, $player] = $this->game();
        $options = app(GetLegalGameActionsQuery::class)->execute($user, $game->id);
        $historyCount = $game->actions()->count();
        app(StageGameAction::class)->execute($user, $game->id, $options->version, $options->options[0]);
        Event::fake([GameChanged::class]);

        $confirmation = app(ConfirmGameAction::class)->execute($user, $game->id, $options->version);
        $this->assertInstanceOf(ConfirmedGameActionData::class, $confirmation);
        $result = $confirmation->game;

        $this->assertInstanceOf(Game::class, $result);
        $this->assertGreaterThan($options->version, $result->version);
        $this->assertSame($historyCount + 1, $result->actions()->count());
        $this->assertNotNull($player->fresh()->homeland);
        $this->assertNull(app(StagedGameActionStore::class)->get($player));
        Event::assertDispatched(GameChanged::class, fn (GameChanged $event): bool => $event->gameId === $game->id);
    }

    public function test_stale_confirmation_preserves_the_selection_and_does_not_execute_it(): void
    {
        [$game, $user, $player] = $this->game();
        $options = app(GetLegalGameActionsQuery::class)->execute($user, $game->id);
        $staged = app(StageGameAction::class)->execute($user, $game->id, $options->version, $options->options[0]);
        $game->increment('version');
        $historyCount = $game->actions()->count();
        Event::fake([GameChanged::class]);

        try {
            app(ConfirmGameAction::class)->execute($user, $game->id, $options->version);
            $this->fail('Expected a stale version to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('version changed', $exception->getMessage());
        }
        $this->assertEquals($staged, app(StagedGameActionStore::class)->get($player));
        $this->assertSame($historyCount, $game->actions()->count());
        $this->assertNull($player->fresh()->homeland);
        Event::assertNotDispatched(GameChanged::class);
    }

    public function test_domain_rejects_finishing_a_turn_when_it_is_not_available(): void
    {
        [$game, $user, $player] = $this->game();
        $before = $game->fresh()->getAttributes();
        try {
            app(StageGameAction::class)->execute($user, $game->id, $game->version, null);
            $this->fail('Expected an unavailable finish-turn action to be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('not available', $exception->getMessage());
        }
        $this->assertNull(app(StagedGameActionStore::class)->get($player));
        $this->assertSame($before, $game->fresh()->getAttributes());
    }

    public function test_workshop_selection_survives_serialization_and_can_be_rolled_back_then_confirmed(): void
    {
        [$game, $user, $player] = $this->game();
        $state = new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '7:0',
                    q: 7,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                    adjacentHexIds: ['8:0']
                ),
                new BoardHexStateData(
                    id: '8:0',
                    q: 8,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['7:0']
                ),
            ]),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(coins: 5, tools: 3)
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
        $game->update(['state' => $state, 'phase' => GamePhase::Actions]);
        $before = $game->fresh()->getAttributes();
        $historyCount = $game->actions()->count();
        $option = new BuildWorkshopOptionData('8:0');
        $stage = app(StageGameAction::class);
        $stage->execute($user, $game->id, $game->version, $option);

        $payload = Cache::get('game:staged-action:'.$player->id);
        $this->assertSame(['version' => $game->version, 'type' => 'build_workshop', 'parameters' => ['hexId' => '8:0']], $payload);
        $this->assertSame($payload, unserialize(serialize($payload), ['allowed_classes' => false]));
        $this->assertEquals($option, app(GetGameStateQuery::class)->execute($user, $game->id)->stagedAction?->option);
        $this->assertSame($before, $game->fresh()->getAttributes());
        app(RollbackGameAction::class)->execute($user, $game->id, $game->version);
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertNull(app(StagedGameActionStore::class)->get($player));

        $stage->execute($user, $game->id, $game->version, $option);
        $confirmation = app(ConfirmGameAction::class)->execute($user, $game->id, $game->version);
        $this->assertSame(GameActionContinuation::YourTurn, $confirmation->continuation);
        $this->assertSame($player->id, $confirmation->nextPlayerId);
        $result = $confirmation->game;
        $this->assertSame(BuildingType::Workshop, $result->state->board->hexes[1]->building?->type);
        $this->assertSame(2, $result->state->players[0]->resources->tools);
        $this->assertSame(3, $result->state->players[0]->resources->coins);
        $this->assertSame($historyCount + 1, $result->actions()->count());
        $this->assertNull(app(StagedGameActionStore::class)->get($player));
    }

    public function test_finish_turn_selection_also_survives_serialization(): void
    {
        [, , $player] = $this->game();
        $store = app(StagedGameActionStore::class);
        $staged = new StagedGameActionData(7, null);
        $store->put($player, $staged);

        $this->assertEquals($staged, $store->get($player));
        $store->forget($player);
        $this->assertNull($store->get($player));
    }

    public function test_legacy_or_unavailable_selections_are_rejected_and_expired_selections_disappear(): void
    {
        [$game, $user, $player] = $this->game();
        $store = app(StagedGameActionStore::class);
        $key = 'game:staged-action:'.$player->id;
        foreach ([new StagedGameActionData($game->version, null), ['version' => 'invalid'],
            ['version' => $game->version, 'type' => 'build_workshop', 'parameters' => ['hexId' => 'invalid']],
            ['version' => $game->version, 'type' => 'finish_turn', 'parameters' => ['unexpected' => true]],
        ] as $invalid) {
            Cache::put($key, $invalid, 60);
            $this->assertNull($store->get($player));
        }

        $options = app(GetLegalGameActionsQuery::class)->execute($user, $game->id);
        $staged = app(StageGameAction::class)->execute($user, $game->id, $game->version, $options->options[0]);
        $this->assertEquals($staged, $store->get($player));
        $other = $game->players()->whereKeyNot($player->id)->sole();
        $this->assertNull($store->get($other));
        $this->travel(61)->minutes();
        $this->assertNull($store->get($player));
    }

    /** @return array{Game, User, GamePlayer} */
    private function game(): array
    {
        $users = User::factory()->count(2)->create();
        $game = Game::factory()->create(['random_seed' => 'domain-staging']);
        foreach ($users as $index => $user) {
            GamePlayer::factory()->ready()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => $index + 1]);
        }
        $game = app(StartGameAction::class)->execute($game, $users[0]);
        $player = $game->players()->findOrFail($game->active_game_player_id);
        $user = $users->firstWhere('id', $player->user_id);
        $this->assertInstanceOf(User::class, $user);
        app(StagedGameActionStore::class)->forget($player);

        return [$game, $user, $player];
    }
}
