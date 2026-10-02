<?php

declare(strict_types=1);

namespace Tests\Feature\GameEngine\Economy;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Actions\ApplyResourceExchangeAction;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Enums\GameActionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ResourceExchangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_active_player_can_exchange_multiple_resources_without_ending_the_turn(): void
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
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlThree: 10),
                    ),
                )],
            ),
        ]);

        $this->actingAs($user)
            ->post(route('games.resource-exchange', $game), [
                'exchanges' => $this->resourceExchanges(
                    powerToScholar: 1,
                    powerToBook: ['law' => 1],
                ),
            ])
            ->assertNoContent();

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame(10, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(1, $game->state->players[0]->resources->scholars);
        $this->assertSame(1, $game->state->players[0]->resources->books->law);
        $this->assertSame($user->id, $game->active_player_id);
        $this->assertSame(GameActionType::ExchangeResources, $game->actions()->sole()->type);
        $this->assertSame(1, $game->actions()->sole()->payload['exchanges']['power_to_book']['law']);
        $this->get(route('games.show', $game))
            ->assertInertia(
                fn (Assert $page) => $page->where('game.data.canFinishCurrentTurn', false),
            );
        $this->post(route('games.current-turn.finish', $game))->assertForbidden();
    }

    public function test_all_resource_exchange_rates_are_applied(): void
    {
        $playerState = new GamePlayerStateData(
            playerId: 1,
            userId: 1,
            color: PlayerColor::Green,
            faction: Faction::Blessed,
            homeland: TerrainType::Forest,
            roundBonus: RoundBonus::Coins,
            resources: new PlayerResourcesData(
                tools: 2,
                scholars: 2,
                power: new PowerBowlsStateData(bowlThree: 15),
            ),
        );
        $playerState->resources->books->law = 1;
        $applyResourceExchange = app(ApplyResourceExchangeAction::class);

        $applyResourceExchange->execute($playerState, $this->resourceExchanges(
            powerToScholar: 1,
            powerToTool: 1,
            powerToCoin: 1,
            scholarToTool: 1,
            toolToCoin: 1,
            powerToBook: ['law' => 1],
            bookToCoin: ['law' => 1],
        ));

        $this->assertSame(1, $playerState->resources->power->bowlThree);
        $this->assertSame(14, $playerState->resources->power->bowlOne);
        $this->assertSame(2, $playerState->resources->scholars);
        $this->assertSame(3, $playerState->resources->tools);
        $this->assertSame(3, $playerState->resources->coins);
        $this->assertSame(1, $playerState->resources->books->law);
    }

    public function test_resource_exchange_batch_is_not_partially_applied(): void
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
        ]);
        $game->update([
            'state' => new GameStateData(
                turnOrder: [$player->id],
                round: new RoundStateData(phase: GamePhase::Actions),
                players: [new GamePlayerStateData(
                    playerId: $player->id,
                    userId: $user->id,
                    color: PlayerColor::Green,
                    faction: Faction::Blessed,
                    homeland: TerrainType::Forest,
                    roundBonus: RoundBonus::Coins,
                    resources: new PlayerResourcesData(
                        power: new PowerBowlsStateData(bowlThree: 5),
                    ),
                )],
            ),
        ]);

        $this->actingAs($user)
            ->post(route('games.resource-exchange', $game), [
                'exchanges' => $this->resourceExchanges(powerToScholar: 1, powerToTool: 1),
            ])
            ->assertSessionHasErrors('exchanges');

        $game->refresh();
        $this->assertSame(5, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(0, $game->state->players[0]->resources->scholars);
        $this->assertSame(0, $game->state->players[0]->resources->tools);
        $this->assertCount(0, $game->actions);
    }

    /**
     * @param array<string, int> $powerToBook
     * @param array<string, int> $bookToCoin
     * @return array<string, int|array<string, int>>
     */
    private function resourceExchanges(
        int $powerToScholar = 0,
        int $powerToTool = 0,
        int $powerToCoin = 0,
        int $scholarToTool = 0,
        int $toolToCoin = 0,
        array $powerToBook = [],
        array $bookToCoin = [],
    ): array {
        $emptyBooks = ['banking' => 0, 'law' => 0, 'engineering' => 0, 'medicine' => 0];

        return [
            'power_to_scholar' => $powerToScholar,
            'power_to_tool' => $powerToTool,
            'power_to_coin' => $powerToCoin,
            'scholar_to_tool' => $scholarToTool,
            'tool_to_coin' => $toolToCoin,
            'power_to_book' => array_replace($emptyBooks, $powerToBook),
            'book_to_coin' => array_replace($emptyBooks, $bookToCoin),
        ];
    }
}
