<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Domain\GameEngine\PlayerAbilities\Enums\Faction;
use App\Domain\GameEngine\PlayerAbilities\Enums\PalaceAbility;
use App\Domain\GameEngine\PlayerAbilities\Enums\RoundBonus;
use App\Domain\GameEngine\Research\Enums\Competency;
use App\Domain\GameEngine\Research\Enums\Innovation;
use App\Domain\GameEngine\Towns\Enums\TownTile;
use App\Mcp\Data\GameComponentData;
use App\Mcp\Enums\GameComponentType;
use App\Mcp\Presentation\GameComponentPresenter;
use App\Mcp\Servers\GameServer;
use App\Mcp\Tools\GetGameComponentTool;
use App\Mcp\Validators\GameComponentValidator;
use App\Models\User;
use DomainException;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameComponentTest extends TestCase
{
    use RefreshDatabase;

    #[DataProvider('components')]
    public function test_each_component_uses_the_engine_description_without_dumping_other_components(GameComponentType $type, string $id, string $description): void
    {
        $data = (new GameComponentValidator())->validate(new Request(['type' => $type->value, 'id' => $id, 'game_id' => 99]));
        $this->assertSame($type, $data->type);
        $this->assertSame(['type' => $type->value, 'id' => $id, 'description' => $description], app(GameComponentPresenter::class)->present($data));
        $this->assertFalse(property_exists($data, 'gameId'));
    }

    public function test_http_returns_only_requested_component_and_does_not_query_games(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('test', ['game:mcp'])->plainTextToken;
        $gameQueries = [];
        DB::listen(function (QueryExecuted $query) use (&$gameQueries): void {
            if (preg_match('/\b(?:games|game_players)\b/', $query->sql) === 1) {
                $gameQueries[] = $query->sql;
            }
        });

        $response = $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_component', 'arguments' => ['type' => 'innovation', 'id' => 'league_of_cities']],
        ])->assertOk()->assertJsonPath('result.isError', false)
            ->assertJsonPath('result.structuredContent', ['type' => 'innovation', 'id' => 'league_of_cities', 'description' => Innovation::LeagueOfCities->description()]);

        $this->assertSame($response->json('result.structuredContent'), json_decode($response->json('result.content.0.text'), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame([], $gameQueries);
        config(['game-mcp.user_id' => $user->id]);
        GameServer::tool(GetGameComponentTool::class, ['type' => 'innovation', 'id' => 'league_of_cities'])
            ->assertOk()->assertSee('league_of_cities')->assertDontSee('sewage_system');
    }

    /** @param array<string, mixed> $arguments */
    #[DataProvider('invalidArguments')]
    public function test_invalid_component_arguments_are_rejected(array $arguments): void
    {
        $this->expectException(ValidationException::class);
        (new GameComponentValidator())->validate(new Request($arguments));
    }

    #[DataProvider('unknownIds')]
    public function test_unknown_or_wrong_category_id_is_rejected(string $id): void
    {
        $this->expectException(DomainException::class);
        app(GameComponentPresenter::class)->present(new GameComponentData(GameComponentType::Innovation, $id));
    }

    public function test_unknown_id_returns_an_mcp_error_without_exposing_files_or_the_catalog(): void
    {
        config(['game-mcp.user_id' => User::factory()->create()->id]);
        GameServer::tool(GetGameComponentTool::class, ['type' => 'innovation', 'id' => '../../.env'])
            ->assertHasErrors()->assertSee('Unknown component ID')->assertDontSee('APP_KEY')->assertDontSee('league_of_cities');
    }

    public function test_schema_has_only_supported_type_and_id_and_is_read_only(): void
    {
        $definition = app(GetGameComponentTool::class)->toArray();
        $this->assertSame(['type', 'id'], array_keys($definition['inputSchema']['properties']));
        $this->assertSame(array_column(GameComponentType::cases(), 'value'), $definition['inputSchema']['properties']['type']['enum']);
        $this->assertEqualsCanonicalizing(['type', 'id'], $definition['inputSchema']['required']);
        $this->assertTrue(((array) $definition['annotations'])['readOnlyHint']);
    }

    public function test_http_component_lookup_and_quick_start_require_authentication_and_game_ability(): void
    {
        $requests = [
            ['method' => 'tools/call', 'params' => ['name' => 'get_component', 'arguments' => ['type' => 'faction', 'id' => 'blessed']]],
            ['method' => 'resources/read', 'params' => ['uri' => 'game://quick-start']],
        ];
        foreach ($requests as $request) {
            $this->postJson('/mcp/game', ['jsonrpc' => '2.0', 'id' => 1, ...$request])->assertUnauthorized();
        }
        $token = User::factory()->create()->createToken('test', ['profile:read'])->plainTextToken;
        foreach ($requests as $request) {
            $this->withToken($token)->postJson('/mcp/game', ['jsonrpc' => '2.0', 'id' => 1, ...$request])->assertForbidden();
        }
    }

    /** @return array<string, array{GameComponentType, string, string}> */
    public static function components(): array
    {
        $cases = [];
        foreach ([
            [GameComponentType::Faction, Faction::class],
            [GameComponentType::Competency, Competency::class],
            [GameComponentType::Innovation, Innovation::class],
            [GameComponentType::Palace, PalaceAbility::class],
            [GameComponentType::RoundBonus, RoundBonus::class],
            [GameComponentType::TownTile, TownTile::class],
        ] as [$type, $class]) {
            foreach ($class::cases() as $component) {
                $cases[$type->value.':'.$component->value] = [$type, $component->value, $component->description()];
            }
        }

        return $cases;
    }

    /** @return list<array{array<string, mixed>}> */
    public static function invalidArguments(): array
    {
        return [
            [[]], [['type' => 'faction']], [['id' => 'blessed']],
            [['type' => 'unknown', 'id' => 'blessed']], [['type' => Faction::class, 'id' => 'blessed']],
            [['type' => 'faction', 'id' => '']], [['type' => 'faction', 'id' => null]],
            [['type' => 'faction', 'id' => ['blessed']]], [['type' => 'faction', 'id' => str_repeat('a', 65)]],
        ];
    }

    /** @return list<array{string}> */
    public static function unknownIds(): array
    {
        return [['unknown'], ['palace_01'], ['../../.env']];
    }
}
