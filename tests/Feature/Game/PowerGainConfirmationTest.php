<?php

declare(strict_types=1);

namespace Tests\Feature\Game;

use App\Domain\Game\Enums\GameStatus;
use App\Domain\GameEngine\Board\Enums\TerrainType;
use App\Domain\GameEngine\Economy\Data\BookSupplyData;
use App\Domain\GameEngine\Economy\Data\PlayerResourcesData;
use App\Domain\GameEngine\Economy\Data\PowerBowlsStateData;
use App\Domain\GameEngine\Economy\Enums\BookAction;
use App\Domain\GameEngine\Interactions\Data\PendingInteractionData;
use App\Domain\GameEngine\Interactions\Enums\PendingInteractionType;
use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Setup\Factories\GameSetupPoolFactory;
use App\Domain\GameEngine\State\Data\GamePlayerStateData;
use App\Domain\GameEngine\State\Data\GameStateData;
use App\Domain\GameEngine\State\Enums\PlayerColor;
use App\Domain\GameEngine\Turns\Data\RoundStateData;
use App\Domain\GameEngine\Turns\Enums\GamePhase;
use App\Events\GameChanged;
use App\Models\Game;
use App\Models\GamePlayer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class PowerGainConfirmationTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, int}> */
    public static function sources(): array
    {
        return [
            'competency' => ['competency-action', 4],
            'psychics' => ['faction-action', 5],
            'book' => ['book-action', 5],
            'town' => ['town', 8],
            'palace' => ['palace-choice', 12],
            'knowledge' => ['scholar', 2],
            'offer' => ['power-offer', 6],
        ];
    }

    #[DataProvider('sources')]
    public function test_loss_requires_confirmation_and_cancel_preserves_the_complete_state(string $source, int $amount): void
    {
        [$game, $user, $payload] = $this->game($source);
        $capacity = $source === 'scholar' ? 1 : 3;
        $before = $game->fresh()->getAttributes();
        Event::fake([GameChanged::class]);
        $url = route('games.'.$source, $game);

        $warning = $this->actingAs($user)->postJson($url, $payload)
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['power_confirmation', 'power_confirmation_key']);
        $this->assertStringContainsString("{$capacity} из {$amount}", $warning->json('errors.power_confirmation.0'));
        $this->assertSame($before, $game->fresh()->getAttributes());
        $this->assertSame(0, $game->actions()->count());
        Event::assertNotDispatched(GameChanged::class);

        $this->postJson($url, $payload)->assertUnprocessable();
        $this->assertSame($before, $game->fresh()->getAttributes());

        $this->withHeader('X-Confirmed-Power-Loss', $warning->json('errors.power_confirmation_key.0'))
            ->postJson($url, $payload)->assertNoContent();

        $game->refresh();
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlOne);
        $this->assertSame(0, $game->state->players[0]->resources->power->bowlTwo);
        $this->assertSame($capacity + 2, $game->state->players[0]->resources->power->bowlThree);
        $this->assertSame(1, $game->actions()->count());
        Event::assertDispatched(GameChanged::class);
    }

    /** @return array<string, array{int, int, bool}> */
    public static function capacities(): array
    {
        return [
            'all power would be lost' => [0, 0, true],
            'one token can move twice' => [1, 0, true],
            'exact capacity' => [2, 0, false],
            'spare capacity' => [2, 1, false],
        ];
    }

    #[DataProvider('capacities')]
    public function test_capacity_counts_both_steps_from_bowl_one(int $one, int $two, bool $warn): void
    {
        [$game, $user] = $this->game('competency-action');
        $state = $game->state;
        $state->players[0]->resources->power = new PowerBowlsStateData($one, $two, 2);
        $game->update(['state' => $state]);
        $response = $this->actingAs($user)->postJson(route('games.competency-action', $game));

        if ($warn) {
            $response->assertUnprocessable()->assertJsonValidationErrors('power_confirmation');
            $this->assertSame(0, $game->actions()->count());
        } else {
            $response->assertNoContent();
            $this->assertSame(1, $game->actions()->count());
        }
    }

    public function test_changed_capacity_requires_a_new_confirmation(): void
    {
        [$game, $user] = $this->game('competency-action');
        $url = route('games.competency-action', $game);
        $warning = $this->actingAs($user)->postJson($url)->assertUnprocessable();
        $state = $game->fresh()->state;
        $state->players[0]->resources->power->bowlTwo = 1;
        $game->update(['state' => $state]);

        $this->withHeader('X-Confirmed-Power-Loss', $warning->json('errors.power_confirmation_key.0'))
            ->postJson($url)->assertUnprocessable()->assertJsonValidationErrors('power_confirmation');
        $this->assertSame(0, $game->actions()->count());
        $this->assertSame(1, $game->fresh()->state->players[0]->resources->power->bowlTwo);
    }

    /** @return array{Game, User, array<string, mixed>} */
    private function game(string $source): array
    {
        $user = User::factory()->create();
        $game = Game::factory()->create([
            'status' => GameStatus::Active,
            'phase' => GamePhase::Actions,
            'active_player_id' => $user->id,
        ]);
        $player = GamePlayer::factory()->create(['game_id' => $game->id, 'user_id' => $user->id, 'seat' => 1]);
        $setupPool = app(GameSetupPoolFactory::class)->create(2);
        $setupPool->bookActions = [BookAction::GainPower];
        $state = new GameStateData(
            turnOrder: [$player->id],
            round: new RoundStateData(phase: GamePhase::Actions),
            players: [new GamePlayerStateData(
                playerId: $player->id,
                userId: $user->id,
                color: PlayerColor::Green,
                faction: $source === 'faction-action' ? Faction::Psychics : Faction::Blessed,
                homeland: TerrainType::Forest,
                roundBonus: RoundBonus::Coins,
                resources: new PlayerResourcesData(
                    scholars: 1,
                    power: new PowerBowlsStateData(0, $source === 'scholar' ? 1 : 3, 2),
                    books: new BookSupplyData(banking: 1),
                ),
                competencyIds: [Competency::Competency07->value],
            )],
            setupPool: $setupPool,
            availableTownTileIds: ['power'],
            availablePalaceIds: ['palace_10'],
        );
        $payload = [];

        if ($source === 'book-action') {
            $payload = ['action' => 'gain_power', 'book_counts' => ['banking' => 1, 'law' => 0, 'engineering' => 0, 'medicine' => 0]];
        } elseif ($source === 'town') {
            $state->pendingInteraction = new PendingInteractionData(PendingInteractionType::ChooseTown, $player->id, ['power'], ['freePalaceTownTile' => true]);
            $payload = ['town_tile' => 'power'];
        } elseif ($source === 'palace-choice') {
            $state->pendingInteraction = new PendingInteractionData(PendingInteractionType::ChoosePalace, $player->id, ['palace_10']);
            $payload = ['palace_id' => 'palace_10'];
        } elseif ($source === 'scholar') {
            $state->players[0]->knowledge->law = 4;
            $payload = ['discipline' => 'law', 'place' => false];
        } elseif ($source === 'power-offer') {
            $state->pendingInteraction = new PendingInteractionData(PendingInteractionType::PowerOffer, $player->id, context: ['powerAmount' => 6, 'buildingPlayerId' => $player->id, 'builtHexId' => '0:0']);
            $payload = ['accept' => true];
        }

        $game->update(['state' => $state, 'active_game_player_id' => $player->id]);

        return [$game, $user, $payload];
    }
}
