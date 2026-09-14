<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Data\BoardHexStateData;
use App\Domain\Game\Data\BoardStateData;
use App\Domain\Game\Data\BuildingStateData;
use App\Domain\Game\Data\GamePlayerStateData;
use App\Domain\Game\Data\GameStateData;
use App\Domain\Game\Data\PendingInteractionData;
use App\Domain\Game\Data\PlayerResourcesData;
use App\Domain\Game\Data\PowerBowlsStateData;
use App\Domain\Game\Data\RoundStateData;
use App\Domain\Game\Enums\BuildingType;
use App\Domain\Game\Enums\Faction;
use App\Domain\Game\Enums\GamePhase;
use App\Domain\Game\Enums\GameStatus;
use App\Domain\Game\Enums\PendingInteractionType;
use App\Domain\Game\Enums\PlayerColor;
use App\Domain\Game\Enums\RoundBonus;
use App\Domain\Game\Enums\TerrainType;
use App\Domain\Game\Services\LegalActionFinder;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Tests\TestCase;

class LegalActionFinderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_it_returns_no_actions_when_it_is_another_players_turn(): void
    {
        [$game, $user] = $this->activeGame();
        $game->active_player_id = User::factory()->create()->id;

        $this->assertSame([], app(LegalActionFinder::class)->execute($game, $user));
    }

    public function test_it_lists_affordable_main_actions_and_their_legal_targets(): void
    {
        [$game, $user, $player] = $this->activeGame();
        $state = $game->state;
        $state->players[0]->resources = new PlayerResourcesData(
            coins: 10,
            tools: 5,
            scholars: 1,
            power: new PowerBowlsStateData(bowlThree: 6),
        );
        $game->state = $state;

        $actions = collect(app(LegalActionFinder::class)->execute($game, $user))->keyBy('type');

        $this->assertTrue($actions->has('pass'));
        $this->assertSame(['1:0'], $actions->get('build_workshop')->parameters['hexIds']);
        $this->assertSame('guild', $actions->get('upgrade_building')->parameters['options'][0]['target']);
        $this->assertTrue($actions->has('advance_shipping'));
        $this->assertTrue($actions->has('advance_terraforming'));
        $this->assertCount(8, $actions->get('send_scholar')->parameters['options']);
        $this->assertCount(6, $actions->get('use_power_action')->parameters['options']);
        $this->assertSame($player->id, $state->players[0]->playerId);
    }

    public function test_a_pending_interaction_hides_normal_actions(): void
    {
        [$game, $user, $player] = $this->activeGame();
        $state = $game->state;
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::ChooseTown,
            $player->id,
            ['town_1', 'town_2'],
        );
        $game->state = $state;

        $actions = app(LegalActionFinder::class)->execute($game, $user);

        $this->assertCount(1, $actions);
        $this->assertSame('choose_town', $actions[0]->type);
        $this->assertSame(['town_1', 'town_2'], $actions[0]->parameters['options']);
    }

    public function test_a_staged_spade_can_only_be_confirmed_or_undone(): void
    {
        [$game, $user, $player] = $this->activeGame();
        $state = $game->state;
        $state->pendingInteraction = new PendingInteractionData(
            PendingInteractionType::SpendSpades,
            $player->id,
            ['1:0'],
            ['selectedHexId' => '1:0'],
        );
        $game->state = $state;

        $types = array_column(
            array_map(static fn ($action): array => $action->toArray(), app(LegalActionFinder::class)->execute($game, $user)),
            'type',
        );

        $this->assertSame(['confirm_spades', 'undo_spades'], $types);
    }

    /** @return array{Game, User, GamePlayer} */
    private function activeGame(): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create([
            'game_id' => $game->id,
            'user_id' => $user->id,
            'seat' => 1,
            'color' => PlayerColor::Green,
            'faction' => Faction::Blessed,
            'homeland' => TerrainType::Forest,
        ]);
        $state = new GameStateData(
            turnOrder: [$player->id],
            board: new BoardStateData(hexes: [
                new BoardHexStateData(
                    id: '0:0',
                    q: 0,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    building: new BuildingStateData(BuildingType::Workshop, $player->id),
                    adjacentHexIds: ['1:0'],
                ),
                new BoardHexStateData(
                    id: '1:0',
                    q: 1,
                    r: 0,
                    initialTerrain: TerrainType::Forest,
                    terrain: TerrainType::Forest,
                    adjacentHexIds: ['0:0'],
                ),
            ]),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
            )],
            round: new RoundStateData(phase: GamePhase::Actions),
        );
        $game->state = $state;

        return [$game, $user, $player];
    }
}
