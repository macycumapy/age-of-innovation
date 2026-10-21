<?php

declare(strict_types=1);

namespace Tests\Feature\Mcp;

use App\Mcp\Resources\GameComponentsResource;
use App\Mcp\Resources\GamePlayGuideResource;
use App\Mcp\Resources\GameQuickStartResource;
use App\Mcp\Resources\GameRulesResource;
use App\Mcp\Server\GameServer;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Laravel\Mcp\Server\Resource;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GameResourcesTest extends TestCase
{
    use RefreshDatabase;

    /** @param class-string<Resource> $resource */
    #[DataProvider('resources')]
    public function test_local_resources_share_the_skill_content(string $resource, string $uri, string $file): void
    {
        GameServer::resource($resource)->assertOk()->assertSee($this->content($file));
        $this->assertSame($uri, app($resource)->uri());
        $this->assertSame('text/markdown', app($resource)->mimeType());
    }

    public function test_http_lists_full_references_but_instructs_clients_to_start_with_quick_start_only(): void
    {
        $token = User::factory()->create()->createToken('game-mcp', ['game:mcp'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/list',
        ])->assertOk()->assertJsonCount(4, 'result.resources')
            ->assertJsonPath('result.resources.0.uri', 'game://rules')
            ->assertJsonPath('result.resources.1.uri', 'game://components')
            ->assertJsonPath('result.resources.2.uri', 'game://play-guide')
            ->assertJsonPath('result.resources.3.uri', 'game://quick-start')
            ->assertJsonPath('result.resources.0.mimeType', 'text/markdown');

        $response = $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 2, 'method' => 'initialize', 'params' => [],
        ])->assertOk();
        foreach (self::resources() as [, $uri]) {
            $this->assertStringContainsString($uri, $response->json('result.instructions'));
        }
        $this->assertStringContainsString('read only game://quick-start', $response->json('result.instructions'));
        $this->assertStringContainsString('not automatically at startup', $response->json('result.instructions'));
    }

    public function test_quick_start_is_short_and_accessible_locally_and_over_http(): void
    {
        $token = User::factory()->create()->createToken('game-mcp', ['game:mcp'])->plainTextToken;
        $response = $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => 'game://quick-start'],
        ])->assertOk()->assertJsonPath('result.contents.0.mimeType', 'text/markdown');
        $text = $response->json('result.contents.0.text');
        $this->assertStringContainsString('If stage_action fails or its result is uncertain, do not call confirm_action.', $text);
        $this->assertStringContainsString('Fetch get_turn_context, choose again from its current actions, and stage again.', $text);
        $this->assertStringContainsString('matches the intended action_id and version', $text);
        $this->assertStringContainsString('Never batch stage_action and confirm_action in parallel', $text);
        $this->assertStringContainsString('base_context_id, game_id, and base_version', $text);
        $this->assertStringContainsString('Timeout preserves context/ID', $text);
        GameServer::resource(GameQuickStartResource::class)->assertOk()->assertSee($text);
        $fullSize = strlen($this->content('SKILL.md')) + strlen($this->content('references/components.md')) + strlen($this->content('references/rules-and-strategy.md'));
        $this->assertLessThan($fullSize / 4, strlen($text));
        foreach (['get_component', 'get_turn_context', 'stage_action', 'confirm_action', 'rollback_action', 'wait_for_turn', 'layout_id'] as $name) {
            $this->assertStringContainsString($name, $text);
        }
    }

    /** @param class-string<Resource> $resource */
    #[DataProvider('resources')]
    public function test_http_reads_the_same_source_files(string $resource, string $uri, string $file): void
    {
        $token = User::factory()->create()->createToken('game-mcp', ['game:mcp'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read', 'params' => ['uri' => $uri],
        ])->assertOk()
            ->assertJsonPath('result.contents.0.uri', $uri)
            ->assertJsonPath('result.contents.0.mimeType', 'text/markdown')
            ->assertJsonPath('result.contents.0.text', $this->content($file));
    }

    public function test_http_resources_require_authentication_and_game_ability(): void
    {
        $messages = [
            ['method' => 'resources/list'],
            ['method' => 'resources/read', 'params' => ['uri' => 'game://rules']],
        ];
        foreach ($messages as $message) {
            $this->postJson('/mcp/game', ['jsonrpc' => '2.0', 'id' => 1, ...$message])->assertUnauthorized();
        }
        $token = User::factory()->create()->createToken('other', ['profile:read'])->plainTextToken;
        foreach ($messages as $message) {
            $this->withToken($token)->postJson('/mcp/game', ['jsonrpc' => '2.0', 'id' => 1, ...$message])->assertForbidden();
        }
    }

    public function test_unknown_resource_does_not_allow_arbitrary_file_reads(): void
    {
        $token = User::factory()->create()->createToken('game-mcp', ['game:mcp'])->plainTextToken;
        $this->withToken($token)->postJson('/mcp/game', [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'resources/read',
            'params' => ['uri' => 'game://../../.env'],
        ])->assertOk()->assertJsonPath('error.code', -32002);
    }

    /** @return array<string, array{class-string<Resource>, string, string}> */
    public static function resources(): array
    {
        return [
            'rules' => [GameRulesResource::class, 'game://rules', 'references/rules-and-strategy.md'],
            'components' => [GameComponentsResource::class, 'game://components', 'references/components.md'],
            'guide' => [GamePlayGuideResource::class, 'game://play-guide', 'SKILL.md'],
        ];
    }

    private function content(string $file): string
    {
        return File::get(base_path('.agents/skills/age-of-innovation-player/'.$file));
    }
}
